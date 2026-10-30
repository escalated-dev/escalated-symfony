<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Role;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\RequiresFeature;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Security\UserPermissions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Surface enough of the host User table for an admin to grant or revoke
 * agent / admin access from the panel.
 *
 * Symfony hosts express the "is_admin" / "is_agent" booleans as the
 * `ROLE_ESCALATED_ADMIN` and `ROLE_ESCALATED_AGENT` roles on the host
 * User entity (see README + EnsureAdminVoter). This controller mirrors
 * the canonical Laravel reference (escalated-laravel#94) but reads /
 * writes those roles instead of `is_admin` / `is_agent` columns. The
 * payload emitted to / accepted by the shared Vue page is unchanged.
 */
#[Route('/admin/users', name: 'escalated.admin.users.')]
class UserController extends AbstractController
{
    public const ROLE_ADMIN = 'ROLE_ESCALATED_ADMIN';
    public const ROLE_AGENT = 'ROLE_ESCALATED_AGENT';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly string $userClass,
        private readonly AccessFeatures $accessFeatures = new AccessFeatures(),
        private readonly ?UserPermissions $userPermissions = null,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $search = trim((string) $request->query->get('search', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;

        [$rows, $total] = $this->loadUsers($search, $page, $perPage);

        $items = array_map(fn (object $u) => $this->serialize($u), $rows);

        // We can't sort on a JSON role column portably, so the underlying
        // query orders by id and we re-sort the page in PHP to match the
        // canonical contract (admins first, then agents, then by id asc).
        usort($items, function (array $a, array $b): int {
            return [$b['is_admin'], $b['is_agent'], $a['id']]
                <=> [$a['is_admin'], $a['is_agent'], $b['id']];
        });

        $users = [
            'data' => $items,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];

        $props = [
            'users' => $users,
            'filters' => ['search' => $search],
            'currentUserId' => $this->currentUserId(),
        ];
        $errors = $this->flashedErrors($request);
        if ([] !== $errors) {
            $props['errors'] = $errors;
        }

        // Escalated roles, only while roles & permissions is enforced: each
        // user's role slugs, and the roles an admin can give them.
        if ($this->accessFeatures->rolesPermissions() && null !== $this->userPermissions) {
            $held = $this->userPermissions->roleSlugsOf(array_column($items, 'id'));
            foreach ($users['data'] as $i => $row) {
                $users['data'][$i]['roles'] = $held[(string) $row['id']] ?? [];
            }
            $props['users'] = $users;
            $props['roles'] = array_map(
                static fn (Role $role): array => ['id' => $role->getId(), 'name' => $role->getName(), 'slug' => $role->getSlug()],
                $this->em->getRepository(Role::class)->findBy([], ['name' => 'ASC']),
            );
        }

        return $this->renderer->render('Escalated/Admin/Users/Index', $props);
    }

    /**
     * Replace a user's Escalated roles (roles & permissions on only).
     */
    #[Route('/{user}/roles', name: 'roles', methods: ['PUT'])]
    #[RequiresFeature(AccessFeatures::ROLES_PERMISSIONS)]
    public function updateRoles(int|string $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        [$roles, $errors] = $this->validateRoleIds($this->payload($request));
        if ([] !== $errors) {
            $this->addFlash('errors', $errors);
            $this->addFlash('error', (string) reset($errors));

            return $this->redirectToRoute('escalated.admin.users.index', [], Response::HTTP_SEE_OTHER);
        }

        $target = $this->em->getRepository($this->userClass)->find($user);
        if (null === $target) {
            throw $this->createNotFoundException('User not found.');
        }
        $targetKey = UserKeyResolver::normalize($this->extractId($target));
        if (null === $targetKey || null === $this->userPermissions) {
            throw $this->createNotFoundException('User not found.');
        }

        // Taking away your own role management would leave nobody able to undo it.
        $currentId = $this->currentUserId();
        if (null !== $currentId && (string) $currentId === (string) $targetKey) {
            $keepsRoleManagement = false;
            foreach ($roles as $role) {
                foreach ($role->getPermissions() as $permission) {
                    $keepsRoleManagement = $keepsRoleManagement || 'role.manage' === $permission->getSlug();
                }
            }
            if (!$keepsRoleManagement) {
                $this->addFlash('error', 'You cannot remove your own role management.');

                return $this->redirectToRoute('escalated.admin.users.index', [], Response::HTTP_SEE_OTHER);
            }
        }

        $this->userPermissions->replace($targetKey, array_values($roles));

        $this->addFlash('success', 'User updated.');

        return $this->redirectToRoute('escalated.admin.users.index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * escalated-laravel's rules for `role_ids` (`present|array`, each
     * `integer|exists:roles,id`), answered the way Laravel answers them:
     * errors keyed `role_ids` or `role_ids.N`, in Laravel's wording.
     *
     * @param array<mixed> $payload
     *
     * @return array{0: list<Role>, 1: array<string, string>}
     */
    private function validateRoleIds(array $payload): array
    {
        if (!\array_key_exists('role_ids', $payload)) {
            return [[], ['role_ids' => 'The role ids field must be present.']];
        }
        $ids = $payload['role_ids'];
        if (!\is_array($ids)) {
            return [[], ['role_ids' => 'The role ids field must be an array.']];
        }

        $errors = [];
        $wanted = [];
        foreach ($ids as $index => $id) {
            $int = filter_var($id, \FILTER_VALIDATE_INT, \FILTER_NULL_ON_FAILURE);
            if (null === $int) {
                $errors['role_ids.'.$index] = 'The role_ids.'.$index.' field must be an integer.';
                continue;
            }
            $wanted[$index] = $int;
        }

        $found = [];
        if ([] !== $wanted) {
            foreach ($this->em->getRepository(Role::class)->findBy(['id' => array_values(array_unique($wanted))]) as $role) {
                $found[(int) $role->getId()] = $role;
            }
        }
        foreach ($wanted as $index => $id) {
            if (!isset($found[$id])) {
                $errors['role_ids.'.$index] = 'The selected role_ids.'.$index.' is invalid.';
            }
        }
        ksort($errors, \SORT_NATURAL);

        return [[] === $errors ? array_values($found) : [], $errors];
    }

    /**
     * Errors flashed by a rejected role change, handed back to the page as
     * `errors` (Inertia's convention) so the screen shows them.
     *
     * @return array<string, string>
     */
    private function flashedErrors(Request $request): array
    {
        if (!$request->hasSession()) {
            return [];
        }
        $session = $request->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return [];
        }

        $errors = [];
        foreach ($session->getFlashBag()->get('errors') as $set) {
            if (\is_array($set)) {
                $errors = [...$errors, ...$set];
            }
        }

        return $errors;
    }

    /**
     * Return [$rows, $total] for the listing page. Extracted so tests can
     * supply a deterministic in-memory list without booting Doctrine — the
     * production query goes through the host's configured user repository.
     *
     * @return array{0: list<object>, 1: int}
     */
    protected function loadUsers(string $search, int $page, int $perPage): array
    {
        $repo = $this->em->getRepository($this->userClass);
        $qb = $repo->createQueryBuilder('u');

        if ('' !== $search) {
            $qb->andWhere('LOWER(u.email) LIKE :term OR LOWER(u.name) LIKE :term')
                ->setParameter('term', '%'.strtolower($search).'%');
        }

        $qb->orderBy('u.id', 'ASC');

        $total = (int) (clone $qb)->select('COUNT(u.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        /** @var list<object> $rows */
        $rows = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [$rows, $total];
    }

    #[Route('/{user}/role', name: 'role', methods: ['PATCH'])]
    public function updateRole(int|string $user, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);
        $role = is_string($payload['role'] ?? null) ? $payload['role'] : null;
        if (!in_array($role, ['admin', 'agent'], true)) {
            throw $this->createNotFoundException('Invalid role.');
        }
        if (!array_key_exists('value', $payload)) {
            throw $this->createNotFoundException('Missing value.');
        }
        $value = (bool) $payload['value'];

        $target = $this->em->getRepository($this->userClass)->find($user);
        if (null === $target) {
            throw $this->createNotFoundException('User not found.');
        }

        // Don't let an admin demote themselves and lock themselves out of
        // the admin panel they're trying to use.
        $currentId = $this->currentUserId();
        if ('admin' === $role
            && false === $value
            && null !== $currentId
            && (string) $currentId === (string) $this->extractId($target)
        ) {
            $this->addFlash('error', 'You cannot remove your own admin role.');

            return $this->redirectToRoute('escalated.admin.users.index');
        }

        $roles = $this->normaliseRoles($target);

        if ('admin' === $role) {
            $roles = $this->toggleRole($roles, self::ROLE_ADMIN, $value);
            // Admins are agents; flipping admin off does not also revoke agent
            // (an ex-admin can still answer tickets unless explicitly demoted).
            if ($value) {
                $roles = $this->toggleRole($roles, self::ROLE_AGENT, true);
            }
        } else {
            $hadAdmin = in_array(self::ROLE_ADMIN, $roles, true);
            $roles = $this->toggleRole($roles, self::ROLE_AGENT, $value);
            if (!$value && $hadAdmin) {
                // Revoking agent from an admin would leave the admin gate on
                // but the agent gate off — confusing. Demote them fully.
                $roles = $this->toggleRole($roles, self::ROLE_ADMIN, false);
            }
        }

        $this->applyRoles($target, $roles);
        $this->em->flush();

        $this->addFlash('success', 'User updated.');

        return $this->redirectToRoute('escalated.admin.users.index');
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $contentType = (string) $request->headers->get('Content-Type');
        $body = (string) $request->getContent();
        if ('' !== $body && str_starts_with($contentType, 'application/json')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    /**
     * Build the row shape expected by the shared Vue page
     * (Escalated/Admin/Users/Index).
     *
     * @return array{id: int|string, name: ?string, email: ?string, is_admin: bool, is_agent: bool}
     */
    private function serialize(object $user): array
    {
        $roles = $this->readRoles($user);

        return [
            'id' => $this->extractId($user),
            'name' => $this->readScalar($user, 'name'),
            'email' => $this->readScalar($user, 'email'),
            'is_admin' => in_array(self::ROLE_ADMIN, $roles, true),
            'is_agent' => in_array(self::ROLE_AGENT, $roles, true),
        ];
    }

    /** @return list<string> */
    private function readRoles(object $user): array
    {
        if (method_exists($user, 'getRoles')) {
            $roles = $user->getRoles();
            if (is_array($roles)) {
                return array_values(array_unique(array_map('strval', $roles)));
            }
        }

        return [];
    }

    /**
     * Like {@see readRoles()} but strips role hierarchies the host User adds
     * automatically (e.g. ROLE_USER injected by Symfony's default getRoles())
     * so the updated set we write back is the "real" stored set.
     *
     * @return list<string>
     */
    private function normaliseRoles(object $user): array
    {
        return $this->readRoles($user);
    }

    /** @param list<string> $roles */
    private function applyRoles(object $user, array $roles): void
    {
        if (method_exists($user, 'setRoles')) {
            // Drop the auto-added ROLE_USER so it does not creep into the
            // stored array each time we save.
            $persisted = array_values(array_filter($roles, fn (string $r) => 'ROLE_USER' !== $r));
            $user->setRoles($persisted);
        }
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private function toggleRole(array $roles, string $role, bool $on): array
    {
        $without = array_values(array_filter($roles, fn (string $r) => $r !== $role));
        if ($on) {
            $without[] = $role;
        }

        return array_values(array_unique($without));
    }

    private function readScalar(object $user, string $property): ?string
    {
        $getter = 'get'.ucfirst($property);
        if (method_exists($user, $getter)) {
            $value = $user->{$getter}();

            return null === $value ? null : (string) $value;
        }
        if (property_exists($user, $property)) {
            $value = $user->{$property} ?? null;

            return null === $value ? null : (string) $value;
        }

        return null;
    }

    private function extractId(object $user): int|string|null
    {
        if (method_exists($user, 'getId')) {
            $id = $user->getId();
            if (null !== $id) {
                return is_numeric($id) ? (int) $id : (string) $id;
            }
        }
        if (property_exists($user, 'id')) {
            $id = $user->id ?? null;
            if (null !== $id) {
                return is_numeric($id) ? (int) $id : (string) $id;
            }
        }

        return null;
    }

    private function currentUserId(): int|string|null
    {
        $user = $this->getUser();
        if (null === $user) {
            return null;
        }

        // Most hosts back the security identifier with the user's PK; some
        // (e.g. the bundled docker fixture) use email. The Escalated entities
        // store numeric ids, so prefer numeric identifiers when present.
        $identifier = $user->getUserIdentifier();
        if (is_numeric($identifier)) {
            return (int) $identifier;
        }

        // Fallback: ask the entity for its own primary key.
        if (is_object($user)) {
            return $this->extractId($user);
        }

        return $identifier;
    }
}
