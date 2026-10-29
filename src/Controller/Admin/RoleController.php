<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\Permission;
use Escalated\Symfony\Entity\Role;
use Escalated\Symfony\Entity\RoleUser;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\RequiresFeature;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/Roles/Index and Form, as escalated-laravel's
 * Admin\RoleController. Only while roles & permissions is switched on; the
 * routes answer 404 otherwise (ADR 2026-10-29-configurable-access-features).
 */
#[Route('/admin/roles', name: 'escalated.admin.roles.')]
#[RequiresFeature(AccessFeatures::ROLES_PERMISSIONS)]
class RoleController extends UiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $counts = [];
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(ru.role) AS role_id', 'COUNT(ru.userId) AS n')
            ->from(RoleUser::class, 'ru')
            ->groupBy('ru.role')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $counts[(int) $row['role_id']] = (int) $row['n'];
        }

        $roles = array_map(fn (Role $role): array => $this->serialize($role) + [
            'users_count' => $counts[(int) $role->getId()] ?? 0,
        ], $this->em->getRepository(Role::class)->findBy([], ['id' => 'ASC']));

        return $this->renderer->render('Escalated/Admin/Roles/Index', ['roles' => $roles]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Roles/Form', [
            'permissions' => $this->groupedPermissions(),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $input = $this->validated($request, null);
        if ($input instanceof Response) {
            return $input;
        }

        $role = new Role($input['name'], Role::slugFor($input['name']));
        $role->setDescription($input['description'])->syncPermissions($input['permissions']);
        $this->em->persist($role);
        $this->em->flush();

        $this->addFlash('success', 'Role created.');

        return $this->redirectToRoute('escalated.admin.roles.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $role = $this->roleOr404($id);

        return $this->renderer->render('Escalated/Admin/Roles/Form', [
            'role' => $this->serialize($role) + [
                'permissions' => array_map(
                    static fn (Permission $permission): array => $permission->toArray(),
                    array_values($role->getPermissions()->toArray()),
                ),
            ],
            'permissions' => $this->groupedPermissions(),
        ]);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $role = $this->roleOr404($id);
        $input = $this->validated($request, $role);
        if ($input instanceof Response) {
            return $input;
        }

        $role->setName($input['name'])->setDescription($input['description'])->syncPermissions($input['permissions']);
        $this->em->flush();

        $this->addFlash('success', 'Role updated.');

        return $this->redirectToRoute('escalated.admin.roles.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $role = $this->roleOr404($id);
        if ($role->isSystem()) {
            $this->addFlash('error', 'System roles cannot be deleted.');

            return $this->back($request, 'escalated.admin.roles.index');
        }

        foreach ($this->em->getRepository(RoleUser::class)->findBy(['role' => $role]) as $held) {
            $this->em->remove($held);
        }
        $this->em->remove($role);
        $this->em->flush();

        $this->addFlash('success', 'Role deleted.');

        return $this->redirectToRoute('escalated.admin.roles.index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array{name: string, description: string|null, permissions: list<Permission>}|Response
     */
    private function validated(Request $request, ?Role $role): array|Response
    {
        $payload = $this->payload($request);
        $errors = [];

        $name = \is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ('' === $name) {
            $errors['name'] = 'The name field is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'The name field must not be greater than 255 characters.';
        } elseif (null === $role) {
            $existing = $this->em->getRepository(Role::class)->findOneBy(['slug' => Role::slugFor($name)]);
            if (null !== $existing) {
                $errors['name'] = 'A role with this name already exists.';
            }
        }

        $description = $payload['description'] ?? null;
        if (null !== $description && !\is_string($description)) {
            $errors['description'] = 'The description field must be a string.';
        }

        $ids = $payload['permissions'] ?? [];
        $permissions = [];
        if (null !== $ids && !\is_array($ids)) {
            $errors['permissions'] = 'The permissions field must be an array.';
        } elseif (\is_array($ids) && [] !== $ids) {
            $wanted = array_values(array_unique(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids)));
            $permissions = $this->em->getRepository(Permission::class)->findBy(['id' => $wanted]);
            if (\count($permissions) !== \count($wanted)) {
                $errors['permissions'] = 'The selected permissions are invalid.';
            }
        }

        if ([] !== $errors) {
            return null === $role
                ? $this->invalid($request, $errors, 'escalated.admin.roles.create')
                : $this->invalid($request, $errors, 'escalated.admin.roles.edit', ['id' => $role->getId()]);
        }

        return [
            'name' => $name,
            'description' => \is_string($description) && '' !== trim($description) ? $description : null,
            'permissions' => array_values($permissions),
        ];
    }

    private function roleOr404(int $id): Role
    {
        return $this->em->getRepository(Role::class)->find($id) ?? throw $this->createNotFoundException('Role not found.');
    }

    /**
     * Permissions grouped by their group, as Laravel's
     * `Permission::all()->groupBy('group')`.
     *
     * @return array<string, list<array<string, mixed>>>|\stdClass
     */
    private function groupedPermissions(): array|\stdClass
    {
        $grouped = [];
        foreach ($this->em->getRepository(Permission::class)->findBy([], ['id' => 'ASC']) as $permission) {
            $grouped[$permission->getGroup()][] = $permission->toArray();
        }

        return [] === $grouped ? new \stdClass() : $grouped;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Role $role): array
    {
        return [
            'id' => $role->getId(),
            'name' => $role->getName(),
            'slug' => $role->getSlug(),
            'description' => $role->getDescription(),
            'is_system' => $role->isSystem(),
            'created_at' => $role->getCreatedAt()->format(\DATE_ATOM),
            'updated_at' => $role->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
