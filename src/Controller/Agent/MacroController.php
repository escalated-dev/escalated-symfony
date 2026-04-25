<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\MacroService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Agent endpoints for listing applicable macros and applying a macro
 * to a specific ticket.
 *
 * See escalated-developer-context/domain-model/workflows-automations-macros.md.
 */
#[Route('/agent', name: 'escalated.agent.')]
class MacroController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MacroService $macroService,
    ) {
    }

    /**
     * List macros visible to the current agent: shared + their own.
     */
    #[Route('/macros', name: 'macros.index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $agentId = $this->currentUserId() ?? 0;
        $macros = $this->macroService->listForAgent($agentId);

        return new JsonResponse(array_map([$this, 'serialize'], $macros));
    }

    /**
     * Apply a macro to a specific ticket.
     */
    #[Route('/tickets/{ticketId}/macros/{macroId}/apply', name: 'macros.apply', methods: ['POST'])]
    public function apply(int $ticketId, int $macroId): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $macro = $this->em->getRepository(Macro::class)->find($macroId);
        if (null === $macro) {
            throw $this->createNotFoundException('Macro not found.');
        }

        $ticket = $this->em->getRepository(Ticket::class)->find($ticketId);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $agentId = $this->currentUserId() ?? 0;
        $this->macroService->apply($macro, $ticket, $agentId);

        return new JsonResponse([
            'ok' => true,
            'ticket' => [
                'id' => $ticket->getId(),
                'status' => $ticket->getStatus(),
                'priority' => $ticket->getPriority(),
                'assigned_to' => $ticket->getAssignedTo(),
            ],
        ]);
    }

    private function currentUserId(): ?int
    {
        $user = $this->getUser();
        if ($user === null) {
            return null;
        }
        $id = $user->getUserIdentifier();
        return is_numeric($id) ? (int) $id : null;
    }

    /** @return array<string, mixed> */
    private function serialize(Macro $m): array
    {
        return [
            'id' => $m->getId(),
            'name' => $m->getName(),
            'description' => $m->getDescription(),
            'actions' => $m->getActions(),
            'is_shared' => $m->isShared(),
            'created_by' => $m->getCreatedBy(),
        ];
    }
}
