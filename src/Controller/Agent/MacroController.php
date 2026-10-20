<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Security\UserKeyResolver;
use Escalated\Symfony\Service\MacroService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
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
        private readonly UserKeyResolver $userKeys,
    ) {
    }

    /**
     * List macros visible to the current agent: shared + their own.
     */
    #[Route('/macros', name: 'macros.index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        // No resolvable key: shared macros only.
        $macros = $this->macroService->listForAgent($this->currentUserId());

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

        // Replies and notes the macro adds are authored by this agent.
        $agentId = $this->currentUserId();
        if (null === $agentId) {
            throw $this->createAccessDeniedException();
        }
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

    /**
     * The signed-in user's host user key -- not getUserIdentifier(), which
     * is usually the email.
     */
    private function currentUserId(): int|string|null
    {
        $user = $this->getUser();

        return null === $user ? null : $this->userKeys->resolve($user);
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
