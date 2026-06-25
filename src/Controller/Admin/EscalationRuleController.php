<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\EscalationRule;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\EscalationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD over EscalationRule rows + manual `run` trigger. Mirrors the
 * Laravel EscalationRuleController. Escalation rules are time-based
 * (evaluated by `escalated:escalations:run`), distinct from event-driven
 * Workflows and the general Automation runner.
 */
#[Route('/admin/escalation-rules', name: 'escalated.admin.escalation_rules.')]
class EscalationRuleController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly EscalationService $service,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $rules = $this->em->getRepository(EscalationRule::class)
            ->findBy([], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        return $this->renderer->render('Escalated/Admin/EscalationRules/Index', [
            'rules' => array_map([$this, 'serialize'], $rules),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $rule = new EscalationRule();
        $this->applyPayload($rule, $this->payload($request));

        $this->em->persist($rule);
        $this->em->flush();

        $this->addFlash('success', 'Escalation rule created.');

        return $this->redirectToRoute('escalated.admin.escalation_rules.index');
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $rule = $this->em->getRepository(EscalationRule::class)->find($id);
        if (null === $rule) {
            throw $this->createNotFoundException('Escalation rule not found.');
        }

        $this->applyPayload($rule, $this->payload($request));
        $this->em->flush();

        $this->addFlash('success', 'Escalation rule updated.');

        return $this->redirectToRoute('escalated.admin.escalation_rules.index');
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $rule = $this->em->getRepository(EscalationRule::class)->find($id);
        if (null === $rule) {
            throw $this->createNotFoundException('Escalation rule not found.');
        }

        $this->em->remove($rule);
        $this->em->flush();

        $this->addFlash('success', 'Escalation rule deleted.');

        return $this->redirectToRoute('escalated.admin.escalation_rules.index');
    }

    /**
     * Manually trigger the evaluator (admin smoke-test).
     */
    #[Route('/run', name: 'run', methods: ['POST'])]
    public function run(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $affected = $this->service->evaluateRules();

        return new JsonResponse(['affected' => $affected]);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        if ('' !== ($json = (string) $request->getContent())
            && str_starts_with((string) $request->headers->get('Content-Type'), 'application/json')
        ) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->request->all();
    }

    /** @param array<string, mixed> $payload */
    private function applyPayload(EscalationRule $rule, array $payload): void
    {
        if (isset($payload['name'])) {
            $rule->setName((string) $payload['name']);
        }
        if (array_key_exists('description', $payload)) {
            $rule->setDescription(null === $payload['description'] ? null : (string) $payload['description']);
        }
        if (array_key_exists('trigger_type', $payload)) {
            $rule->setTriggerType(null === $payload['trigger_type'] ? null : (string) $payload['trigger_type']);
        }
        if (isset($payload['conditions']) && is_array($payload['conditions'])) {
            $rule->setConditions($payload['conditions']);
        }
        if (isset($payload['actions']) && is_array($payload['actions'])) {
            $rule->setActions($payload['actions']);
        }
        if (array_key_exists('order', $payload)) {
            $rule->setSortOrder((int) $payload['order']);
        }
        if (array_key_exists('is_active', $payload)) {
            $rule->setIsActive((bool) $payload['is_active']);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(EscalationRule $r): array
    {
        return [
            'id' => $r->getId(),
            'name' => $r->getName(),
            'description' => $r->getDescription(),
            'trigger_type' => $r->getTriggerType(),
            'conditions' => $r->getConditions(),
            'actions' => $r->getActions(),
            'order' => $r->getSortOrder(),
            'is_active' => $r->isActive(),
            'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $r->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
