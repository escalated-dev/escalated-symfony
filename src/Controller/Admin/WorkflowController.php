<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Entity\WorkflowLog;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\WorkflowEngine;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Workflows admin screen. What goes over the wire is fixed by
 * escalated-developer-context domain-model/workflow-admin-contract.md: the
 * shared `Escalated/Admin/Workflows/*` pages, and the body the shared builder
 * posts (`trigger_event` dotted, conditions `{all|any: [...]}`, actions
 * `[{type, value}]`, all top-level).
 *
 * Workflows are admin and event-driven; see domain-model/workflows-automations-macros.md.
 */
#[Route('/admin/workflows', name: 'escalated.admin.workflows.')]
class WorkflowController extends AbstractController
{
    /** How many executions the Logs page shows, newest first. */
    private const LOGS_LIMIT = 100;

    private const TRIGGER_LABELS = [
        'sla.breached' => 'SLA Breached',
        'sla.warning' => 'SLA Warning',
    ];

    private const ACTION_LABELS = [
        'change_status' => 'Change Status',
        'change_priority' => 'Change Priority',
        'add_tag' => 'Add Tag',
        'remove_tag' => 'Remove Tag',
        'set_department' => 'Set Department',
        'assign_agent' => 'Assign Agent',
        'add_note' => 'Add Internal Note',
        'insert_canned_reply' => 'Send Canned Reply',
        'add_follower' => 'Add Follower',
        'delay' => 'Delay (minutes)',
    ];

    private const OPERATOR_LABELS = [
        'equals' => 'Equals',
        'not_equals' => 'Does Not Equal',
        'contains' => 'Contains',
        'not_contains' => 'Does Not Contain',
        'starts_with' => 'Starts With',
        'ends_with' => 'Ends With',
        'greater_than' => 'Greater Than',
        'less_than' => 'Less Than',
        'greater_or_equal' => 'Greater Than or Equal',
        'less_or_equal' => 'Less Than or Equal',
        'is_empty' => 'Is Empty',
        'is_not_empty' => 'Is Not Empty',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Workflows/Index', [
            'workflows' => array_map([$this, 'serialize'], $this->ordered()),
        ]);
    }

    #[Route('/create', name: 'create', methods: ['GET'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Workflows/Form', [
            'workflow' => null,
            ...$this->formOptions(),
            ...$this->errorProps($request),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);
        if ([] !== $errors = $this->validate($payload)) {
            return $this->invalid($request, $errors, 'escalated.admin.workflows.create');
        }

        $max = $this->em->createQueryBuilder()
            ->select('MAX(w.position)')
            ->from(Workflow::class, 'w')
            ->getQuery()
            ->getSingleScalarResult();

        $workflow = new Workflow();
        $workflow->setPosition(null === $max ? 0 : (int) $max + 1);
        $this->apply($workflow, $payload);

        $this->em->persist($workflow);
        $this->em->flush();

        $this->addFlash('success', 'Workflow created.');

        return $this->redirectToRoute('escalated.admin.workflows.index');
    }

    #[Route('/logs', name: 'logs', methods: ['GET'])]
    public function logs(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $criteria = [];
        $workflowId = $request->query->getInt('workflow');
        if ($workflowId > 0) {
            $criteria['workflow'] = $workflowId;
        }

        /** @var list<WorkflowLog> $logs */
        $logs = $this->em->getRepository(WorkflowLog::class)
            ->findBy($criteria, ['createdAt' => 'DESC', 'id' => 'DESC'], self::LOGS_LIMIT);

        return $this->renderer->render('Escalated/Admin/Workflows/Logs', [
            'logs' => array_map(static fn (WorkflowLog $log): array => $log->toArray(), $logs),
            'workflows' => array_map(
                static fn (Workflow $w): array => ['id' => $w->getId(), 'name' => $w->getName()],
                $this->ordered(),
            ),
        ]);
    }

    /**
     * The contract sends `workflow_ids`; `ids` is the older name and still
     * works. An Inertia visit gets a redirect, anything else JSON.
     */
    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $payload = $this->payload($request);
        $ids = $payload['workflow_ids'] ?? $payload['ids'] ?? null;
        if (!\is_array($ids)) {
            return $this->invalid($request, ['workflow_ids' => 'The workflow order is required.'], 'escalated.admin.workflows.index');
        }

        $repository = $this->em->getRepository(Workflow::class);
        foreach (array_values($ids) as $position => $id) {
            if (!is_numeric($id)) {
                continue;
            }
            $repository->find((int) $id)?->setPosition($position);
        }
        $this->em->flush();

        if (!$request->headers->has('X-Inertia') && 'json' === $request->getPreferredFormat()) {
            return new JsonResponse(['success' => true]);
        }

        return $this->redirectToRoute('escalated.admin.workflows.index');
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        return $this->renderer->render('Escalated/Admin/Workflows/Form', [
            'workflow' => $this->serialize($this->find($id)),
            ...$this->formOptions(),
            ...$this->errorProps($request),
        ]);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $workflow = $this->find($id);
        $payload = $this->payload($request);
        if ([] !== $errors = $this->validate($payload)) {
            return $this->invalid($request, $errors, 'escalated.admin.workflows.edit', ['id' => $id]);
        }

        $this->apply($workflow, $payload);
        $this->em->flush();

        $this->addFlash('success', 'Workflow updated.');

        return $this->redirectToRoute('escalated.admin.workflows.index');
    }

    #[Route('/{id}', name: 'destroy', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function destroy(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $workflow = $this->find($id);
        // Execution logs reference the workflow; they go with it.
        $this->em->createQueryBuilder()
            ->delete(WorkflowLog::class, 'l')
            ->where('l.workflow = :workflow')
            ->setParameter('workflow', $workflow->getId())
            ->getQuery()
            ->execute();
        $this->em->remove($workflow);
        $this->em->flush();

        $this->addFlash('success', 'Workflow deleted.');

        return $this->redirectToRoute('escalated.admin.workflows.index');
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function toggle(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $workflow = $this->find($id);
        $workflow->setIsActive(!$workflow->isActive());
        $this->em->flush();

        $this->addFlash('success', $workflow->isActive() ? 'Workflow enabled.' : 'Workflow disabled.');

        return $this->redirectToRoute('escalated.admin.workflows.index');
    }

    /** @return list<Workflow> */
    private function ordered(): array
    {
        return $this->em->getRepository(Workflow::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);
    }

    private function find(int $id): Workflow
    {
        $workflow = $this->em->getRepository(Workflow::class)->find($id);
        if (null === $workflow) {
            throw $this->createNotFoundException('Workflow not found.');
        }

        return $workflow;
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if ('' !== ($json = (string) $request->getContent())
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')
        ) {
            $decoded = json_decode($json, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return $request->request->all();
    }

    /**
     * The contract's validation: `name`, `trigger_event` and at least one
     * action are required; every action needs a type the engine runs.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, string> field => message
     */
    private function validate(array $payload): array
    {
        $errors = [];

        $name = $payload['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            $errors['name'] = 'The name field is required.';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'The name may not be longer than 255 characters.';
        }

        $trigger = $payload['trigger_event'] ?? null;
        if (!\is_string($trigger) || '' === trim($trigger)) {
            $errors['trigger_event'] = 'The trigger event field is required.';
        }

        if (\array_key_exists('description', $payload) && null !== $payload['description'] && !\is_string($payload['description'])) {
            $errors['description'] = 'The description must be text.';
        }

        $conditions = $payload['conditions'] ?? null;
        if (null !== $conditions && !\is_array($conditions)) {
            $errors['conditions'] = 'The conditions must be an object.';
        }

        $actions = $payload['actions'] ?? null;
        if (!\is_array($actions) || [] === $actions) {
            $errors['actions'] = 'At least one action is required.';
        } else {
            foreach (array_values($actions) as $i => $action) {
                $type = \is_array($action) ? ($action['type'] ?? null) : null;
                if (!\is_string($type) || !\in_array($type, WorkflowEngine::HANDLED_ACTIONS, true)) {
                    $errors["actions.$i.type"] = 'The selected action type is invalid.';
                }
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $payload */
    private function apply(Workflow $workflow, array $payload): void
    {
        $workflow->setName(trim((string) $payload['name']));
        $description = $payload['description'] ?? null;
        $workflow->setDescription(\is_string($description) && '' !== trim($description) ? $description : null);
        // A legacy name posted by an older form is stored as its canonical name.
        $workflow->setTriggerEvent(EventNames::canonical(trim((string) $payload['trigger_event'])));

        $conditions = $payload['conditions'] ?? null;
        $workflow->setConditions(\is_array($conditions) && [] !== $conditions ? $conditions : ['all' => []]);

        $workflow->setActions(array_map(
            static fn (array $action): array => ['type' => (string) $action['type'], 'value' => $action['value'] ?? ''],
            array_values($payload['actions']),
        ));

        if (\array_key_exists('is_active', $payload)) {
            $workflow->setIsActive(filter_var($payload['is_active'], \FILTER_VALIDATE_BOOL));
        }
    }

    /**
     * Redirect back with the errors in the session, per the Inertia
     * convention: flashed under `errors` (field => message), and also as an
     * `error` flash message for hosts that only show flash messages.
     *
     * @param array<string, string> $errors
     * @param array<string, mixed>  $parameters
     */
    private function invalid(Request $request, array $errors, string $fallbackRoute, array $parameters = []): Response
    {
        if (!$request->headers->has('X-Inertia') && 'json' === $request->getPreferredFormat()) {
            return new JsonResponse(['message' => reset($errors), 'errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('errors', $errors);
        $this->addFlash('error', (string) reset($errors));

        $referer = (string) $request->headers->get('referer', '');
        $path = parse_url($referer, \PHP_URL_PATH);
        $host = parse_url($referer, \PHP_URL_HOST);
        if (\is_string($path) && str_starts_with($path, '/') && (null === $host || $host === $request->getHost())) {
            $query = parse_url($referer, \PHP_URL_QUERY);

            return new RedirectResponse($path.(\is_string($query) ? '?'.$query : ''), Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute($fallbackRoute, $parameters, Response::HTTP_SEE_OTHER);
    }

    /**
     * Errors flashed by a failed save, handed to the Form page as `errors`.
     *
     * @return array{errors?: array<string, string>}
     */
    private function errorProps(Request $request): array
    {
        if (!$request->hasSession()) {
            return [];
        }

        $flashed = $request->getSession()->getFlashBag()->get('errors');
        $errors = [];
        foreach ($flashed as $set) {
            if (\is_array($set)) {
                $errors = [...$errors, ...$set];
            }
        }

        return [] === $errors ? [] : ['errors' => $errors];
    }

    /**
     * The Form page's option lists, as the contract's preferred `[{value, label}]`.
     * Each lists exactly what this backend fires, runs or evaluates.
     *
     * @return array{trigger_events: list<array{value: string, label: string}>, action_types: list<array{value: string, label: string}>, operators: list<array{value: string, label: string}>}
     */
    private function formOptions(): array
    {
        return [
            'trigger_events' => array_map(
                static fn (string $event): array => ['value' => $event, 'label' => self::TRIGGER_LABELS[$event] ?? self::humanize($event)],
                EventNames::EMITTED,
            ),
            'action_types' => array_map(
                static fn (string $type): array => ['value' => $type, 'label' => self::ACTION_LABELS[$type]],
                WorkflowEngine::OFFERED_ACTIONS,
            ),
            'operators' => array_map(
                static fn (string $op): array => ['value' => $op, 'label' => self::OPERATOR_LABELS[$op] ?? self::humanize($op)],
                WorkflowEngine::OPERATORS,
            ),
        ];
    }

    private static function humanize(string $value): string
    {
        return ucwords(str_replace(['.', '_'], ' ', $value));
    }

    /**
     * The contract's workflow object.
     *
     * @return array<string, mixed>
     */
    private function serialize(Workflow $workflow): array
    {
        $trigger = EventNames::canonical($workflow->getTriggerEvent());

        return [
            'id' => $workflow->getId(),
            'name' => $workflow->getName(),
            'description' => $workflow->getDescription(),
            'trigger_event' => $trigger,
            'trigger' => $trigger,
            'conditions' => $workflow->getConditions(),
            'actions' => $workflow->getActions(),
            'is_active' => $workflow->isActive(),
            'position' => $workflow->getPosition(),
            'stop_on_match' => $workflow->isStopOnMatch(),
            'created_at' => $workflow->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $workflow->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
