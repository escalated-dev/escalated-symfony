<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\BusinessSchedule;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\DepartmentAgent;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Repository\DepartmentRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Escalated/Admin/Departments/Index and Form, as escalated-laravel's
 * Admin\DepartmentController.
 */
#[Route('/admin/departments', name: 'escalated.admin.departments.')]
class DepartmentController extends UiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DepartmentRepository $departmentRepository,
        private readonly UiRendererInterface $renderer,
        private readonly SluggerInterface $slugger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $tickets = $this->counts(Ticket::class, 'department', 'deletedAt IS NULL');
        $agents = $this->counts(DepartmentAgent::class, 'department', null, 'agentId');

        $departments = array_map(fn (Department $d): array => $this->serialize($d) + [
            'tickets_count' => $tickets[$d->getId()] ?? 0,
            'agents_count' => $agents[$d->getId()] ?? 0,
        ], $this->departmentRepository->findBy([], ['name' => 'ASC']));

        return $this->renderer->render('Escalated/Admin/Departments/Index', [
            'departments' => $departments,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Departments/Form', ['department' => null]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $department = new Department();
        if ([] !== $errors = $this->apply($department, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.departments.create');
        }

        $this->em->persist($department);
        $this->em->flush();

        $this->addFlash('success', 'Department created.');

        return $this->redirectToRoute('escalated.admin.departments.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Departments/Form', [
            'department' => $this->serialize($this->find($id)),
        ]);
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $department = $this->find($id);
        if ([] !== $errors = $this->apply($department, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.departments.edit', ['id' => $id]);
        }
        $this->em->flush();

        $this->addFlash('success', 'Department updated.');

        return $this->redirectToRoute('escalated.admin.departments.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    #[Route('/{id}', name: 'delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $this->em->remove($this->find($id));
        $this->em->flush();

        $this->addFlash('success', 'Department deleted.');

        return $this->redirectToRoute('escalated.admin.departments.index', [], Response::HTTP_SEE_OTHER);
    }

    private function find(int $id): Department
    {
        $department = $this->departmentRepository->find($id);
        if (null === $department) {
            throw $this->createNotFoundException('Department not found.');
        }

        return $department;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string> errors
     */
    private function apply(Department $department, array $payload): array
    {
        $creating = null === $department->getId();
        $errors = [];

        if ($creating || \array_key_exists('name', $payload)) {
            $name = \is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
            if ('' === $name || mb_strlen($name) > 255) {
                $errors['name'] = 'The name field is required.';
            } else {
                $department->setName($name);
            }
        }

        $slug = \is_string($payload['slug'] ?? null) && '' !== trim($payload['slug'])
            ? strtolower((string) $this->slugger->slug(trim($payload['slug'])))
            : ($creating || \array_key_exists('name', $payload) ? strtolower((string) $this->slugger->slug($department->getName())) : $department->getSlug());
        $taken = '' === $slug ? null : $this->departmentRepository->findOneBy(['slug' => $slug]);
        if (null !== $taken && $taken->getId() !== $department->getId()) {
            $errors['slug'] = 'The slug has already been taken.';
        } elseif (!isset($errors['name'])) {
            $department->setSlug($slug);
        }

        if (\array_key_exists('description', $payload)) {
            $department->setDescription(\is_string($payload['description']) && '' !== $payload['description'] ? $payload['description'] : null);
        }
        if (\array_key_exists('is_active', $payload)) {
            $department->setIsActive(filter_var($payload['is_active'], \FILTER_VALIDATE_BOOL));
        }
        // Only when sent: the shared form has no schedule field.
        if (\array_key_exists('business_schedule_id', $payload)) {
            $scheduleId = $payload['business_schedule_id'];
            $schedule = null === $scheduleId || '' === $scheduleId ? null
                : (is_numeric($scheduleId) ? $this->em->getRepository(BusinessSchedule::class)->find((int) $scheduleId) : null);
            if (null !== $scheduleId && '' !== $scheduleId && null === $schedule) {
                $errors['business_schedule_id'] = 'The selected business schedule is invalid.';
            } else {
                $department->setBusinessSchedule($schedule);
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Department $d): array
    {
        return [
            'id' => $d->getId(),
            'name' => $d->getName(),
            'slug' => $d->getSlug(),
            'description' => $d->getDescription(),
            'is_active' => $d->isActive(),
            'business_schedule_id' => $d->getBusinessSchedule()?->getId(),
            'created_at' => $d->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $d->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param class-string $entity
     *
     * @return array<int, int> department id => rows
     */
    private function counts(string $entity, string $field, ?string $where = null, string $counted = 'id'): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('IDENTITY(e.'.$field.') AS id', 'COUNT(e.'.$counted.') AS n')
            ->from($entity, 'e')
            ->where('e.'.$field.' IS NOT NULL')
            ->groupBy('e.'.$field);
        if (null !== $where) {
            $qb->andWhere('e.'.$where);
        }

        $counts = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        return $counts;
    }
}
