<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Controller\TicketDeskController;
use Escalated\Symfony\Controller\UiController;
use Escalated\Symfony\Entity\BusinessSchedule;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Rendering\TicketPresenter;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Escalated/Admin/SlaPolicies/Index and Form, as escalated-laravel's
 * Admin\SlaPolicyController. Hours are kept per priority, as SlaService
 * reads them.
 */
#[Route('/admin/sla-policies', name: 'escalated.admin.sla-policies.')]
class SlaPolicyController extends UiController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly TicketPresenter $presenter,
        #[Autowire('%escalated.targeted_slas%')]
        private readonly bool $targetedSlas = false,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $counts = [];
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(t.slaPolicy) AS id', 'COUNT(t.id) AS n')
            ->from(Ticket::class, 't')
            ->where('t.slaPolicy IS NOT NULL')
            ->andWhere('t.deletedAt IS NULL')
            ->groupBy('t.slaPolicy')
            ->getQuery()
            ->getArrayResult();
        foreach ($rows as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }

        $policies = array_map(fn (SlaPolicy $p): array => $this->serialize($p) + ['tickets_count' => $counts[$p->getId()] ?? 0],
            $this->em->getRepository(SlaPolicy::class)->findBy([], ['name' => 'ASC']));

        return $this->renderer->render('Escalated/Admin/SlaPolicies/Index', [
            'policies' => $policies,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/SlaPolicies/Form', ['policy' => null] + $this->formOptions());
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $policy = new SlaPolicy();
        if ([] !== $errors = $this->apply($policy, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.sla-policies.create');
        }
        $this->em->persist($policy);
        $this->em->flush();

        $this->addFlash('success', 'SLA policy created.');

        return $this->redirectToRoute('escalated.admin.sla-policies.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function edit(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/SlaPolicies/Form', ['policy' => $this->serialize($this->find($id))] + $this->formOptions());
    }

    #[Route('/{id}', name: 'update', requirements: ['id' => '\d+'], methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $policy = $this->find($id);
        if ([] !== $errors = $this->apply($policy, $this->payload($request))) {
            return $this->invalid($request, $errors, 'escalated.admin.sla-policies.edit', ['id' => $id]);
        }
        $this->em->flush();

        $this->addFlash('success', 'SLA policy updated.');

        return $this->redirectToRoute('escalated.admin.sla-policies.index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'destroy', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function destroy(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $policy = $this->find($id);
        // Tickets keep their deadlines; they just stop pointing at the policy.
        $this->em->createQueryBuilder()
            ->update(Ticket::class, 't')
            ->set('t.slaPolicy', 'NULL')
            ->where('t.slaPolicy = :policy')
            ->setParameter('policy', $policy)
            ->getQuery()
            ->execute();
        $this->em->remove($policy);
        $this->em->flush();

        $this->addFlash('success', 'SLA policy deleted.');

        return $this->redirectToRoute('escalated.admin.sla-policies.index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'priorities' => TicketDeskController::priorities(),
            'targeted' => $this->targetedSlas,
            'departments' => $this->presenter->departments(),
            'ticketTypes' => Ticket::TYPES,
        ];
    }

    private function find(int $id): SlaPolicy
    {
        $policy = $this->em->getRepository(SlaPolicy::class)->find($id);
        if (null === $policy) {
            throw $this->createNotFoundException('SLA policy not found.');
        }

        return $policy;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, string>
     */
    private function apply(SlaPolicy $policy, array $payload): array
    {
        $errors = [];

        $name = \is_string($payload['name'] ?? null) ? trim($payload['name']) : '';
        if ('' === $name || mb_strlen($name) > 255) {
            $errors['name'] = 'The name field is required.';
        }
        $hours = [];
        foreach (['first_response_hours', 'resolution_hours'] as $field) {
            $given = $payload[$field] ?? null;
            if (!\is_array($given)) {
                $errors[$field] = sprintf('The %s field is required.', str_replace('_', ' ', $field));
                continue;
            }
            $hours[$field] = [];
            foreach ($given as $priority => $value) {
                if (!\in_array($priority, TicketDeskController::priorities(), true)) {
                    continue;
                }
                if (null === $value || '' === $value) {
                    continue;
                }
                if (!is_numeric($value) || (float) $value < 0) {
                    $errors[$field] = 'Hours must be numbers of zero or more.';
                    continue 2;
                }
                $hours[$field][$priority] = (float) $value;
            }
        }
        $department = null;
        $departmentId = $payload['department_id'] ?? null;
        if (null !== $departmentId && '' !== $departmentId) {
            $department = is_numeric($departmentId) ? $this->em->getRepository(Department::class)->find((int) $departmentId) : null;
            if (null === $department) {
                $errors['department_id'] = 'The selected department is invalid.';
            }
        }
        $type = $payload['ticket_type'] ?? null;
        if (null !== $type && '' !== $type && !\in_array($type, Ticket::TYPES, true)) {
            $errors['ticket_type'] = 'The selected ticket type is invalid.';
        }
        // Only when sent: the shared form has no schedule field, and saving
        // it must not take away a schedule set some other way.
        $schedule = $policy->getBusinessSchedule();
        if (\array_key_exists('business_schedule_id', $payload)) {
            $scheduleId = $payload['business_schedule_id'];
            $schedule = null === $scheduleId || '' === $scheduleId ? null
                : (is_numeric($scheduleId) ? $this->em->getRepository(BusinessSchedule::class)->find((int) $scheduleId) : null);
            if (null !== $scheduleId && '' !== $scheduleId && null === $schedule) {
                $errors['business_schedule_id'] = 'The selected business schedule is invalid.';
            }
        }
        if ([] !== $errors) {
            return $errors;
        }

        $policy->setBusinessSchedule($schedule);
        $policy->setName($name)
            ->setDescription(\is_string($payload['description'] ?? null) && '' !== $payload['description'] ? $payload['description'] : null)
            ->setFirstResponseHours($hours['first_response_hours'])
            ->setResolutionHours($hours['resolution_hours'])
            ->setBusinessHoursOnly(filter_var($payload['business_hours_only'] ?? false, \FILTER_VALIDATE_BOOL))
            ->setIsDefault(filter_var($payload['is_default'] ?? false, \FILTER_VALIDATE_BOOL))
            ->setIsActive(filter_var($payload['is_active'] ?? true, \FILTER_VALIDATE_BOOL));
        $policy->setDepartment($department);
        $policy->setTicketType(\is_string($type) && '' !== $type ? $type : null);

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(SlaPolicy $p): array
    {
        return [
            'id' => $p->getId(),
            'name' => $p->getName(),
            'description' => $p->getDescription(),
            'first_response_hours' => (object) $p->getFirstResponseHours(),
            'resolution_hours' => (object) $p->getResolutionHours(),
            'business_hours_only' => $p->isBusinessHoursOnly(),
            'business_schedule_id' => $p->getBusinessSchedule()?->getId(),
            'is_default' => $p->isDefault(),
            'is_active' => $p->isActive(),
            'department_id' => $p->getDepartment()?->getId(),
            'ticket_type' => $p->getTicketType(),
            'created_at' => $p->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $p->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
