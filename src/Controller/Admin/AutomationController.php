<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Automation;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\AutomationRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD over Automation rows + manual `run` trigger.
 *
 * Distinct from the WorkflowEngine controller (event-driven) and
 * MacroController (agent manual). See escalated-developer-context/
 * domain-model/workflows-automations-macros.md.
 */
#[Route('/admin/automations', name: 'escalated.admin.automations.')]
class AutomationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UiRendererInterface $renderer,
        private readonly AutomationRunner $runner,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $automations = $this->em->getRepository(Automation::class)
            ->findBy([], ['position' => 'ASC', 'id' => 'ASC']);

        return $this->renderer->render('Escalated/Admin/Automations/Index', [
            'automations' => array_map([$this, 'serialize'], $automations),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $automation = new Automation();
        $this->applyPayload($automation, $this->payload($request));

        $this->em->persist($automation);
        $this->em->flush();

        $this->addFlash('success', 'Automation created.');

        return $this->redirectToRoute('escalated.admin.automations.index');
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH', 'PUT'])]
    public function update(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $automation = $this->em->getRepository(Automation::class)->find($id);
        if (null === $automation) {
            throw $this->createNotFoundException('Automation not found.');
        }

        $this->applyPayload($automation, $this->payload($request));
        $this->em->flush();

        $this->addFlash('success', 'Automation updated.');

        return $this->redirectToRoute('escalated.admin.automations.index');
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $automation = $this->em->getRepository(Automation::class)->find($id);
        if (null === $automation) {
            throw $this->createNotFoundException('Automation not found.');
        }

        $this->em->remove($automation);
        $this->em->flush();

        $this->addFlash('success', 'Automation deleted.');

        return $this->redirectToRoute('escalated.admin.automations.index');
    }

    /**
     * Manually trigger the runner. Useful for admin smoke-tests
     * without waiting for the next scheduled tick.
     */
    #[Route('/run', name: 'run', methods: ['POST'])]
    public function run(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $affected = $this->runner->run();

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
    private function applyPayload(Automation $automation, array $payload): void
    {
        if (isset($payload['name'])) {
            $automation->setName((string) $payload['name']);
        }
        if (array_key_exists('description', $payload)) {
            $automation->setDescription(null === $payload['description'] ? null : (string) $payload['description']);
        }
        if (isset($payload['conditions']) && is_array($payload['conditions'])) {
            $automation->setConditions($payload['conditions']);
        }
        if (isset($payload['actions']) && is_array($payload['actions'])) {
            $automation->setActions($payload['actions']);
        }
        if (array_key_exists('active', $payload)) {
            $automation->setActive((bool) $payload['active']);
        }
        if (array_key_exists('position', $payload)) {
            $automation->setPosition((int) $payload['position']);
        }
    }

    /** @return array<string, mixed> */
    private function serialize(Automation $a): array
    {
        return [
            'id' => $a->getId(),
            'name' => $a->getName(),
            'description' => $a->getDescription(),
            'conditions' => $a->getConditions(),
            'actions' => $a->getActions(),
            'active' => $a->isActive(),
            'position' => $a->getPosition(),
            'last_run_at' => $a->getLastRunAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $a->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
