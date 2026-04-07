<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\AssignmentService;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/agent/tickets', name: 'escalated.agent.tickets.')]
class TicketController extends AbstractController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly AssignmentService $assignmentService,
        private readonly UiRendererInterface $renderer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $tickets = $this->ticketService->list($request->query->all());

        return $this->renderer->render('Escalated/Agent/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $request->query->all(),
        ]);
    }

    #[Route('/{reference}', name: 'show', methods: ['GET'])]
    public function show(string $reference): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        return $this->renderer->render('Escalated/Agent/Tickets/Show', [
            'ticket' => $ticket,
        ]);
    }

    #[Route('/{reference}/reply', name: 'reply', methods: ['POST'])]
    public function reply(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $isNote = (bool) $request->request->get('is_internal_note', false);

        $this->ticketService->addReply(
            $ticket,
            (int) $this->getUser()->getUserIdentifier(),
            $request->request->get('body', ''),
            $isNote,
            get_class($this->getUser()),
        );

        $this->addFlash('success', $isNote ? 'Internal note added.' : 'Reply sent.');

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    #[Route('/{reference}/assign', name: 'assign', methods: ['POST'])]
    public function assign(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $agentId = (int) $request->request->get('agent_id');
        $this->assignmentService->assign(
            $ticket,
            $agentId,
            (int) $this->getUser()->getUserIdentifier()
        );

        $this->addFlash('success', 'Ticket assigned.');

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    #[Route('/{reference}/status', name: 'status', methods: ['POST'])]
    public function changeStatus(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $newStatus = $request->request->get('status');
        $this->ticketService->changeStatus(
            $ticket,
            $newStatus,
            (int) $this->getUser()->getUserIdentifier()
        );

        $this->addFlash('success', 'Ticket status updated.');

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }
}
