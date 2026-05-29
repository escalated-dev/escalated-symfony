<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\TicketCustomActionTriggeredEvent;
use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\AssignmentService;
use Escalated\Symfony\Service\TicketActionRegistry;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
        private readonly TicketActionRegistry $ticketActions,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Serializes the visible custom actions for a ticket, adding url + method.
     *
     * @return array<int, array<string, mixed>>
     */
    private function customActionsForTicket(Ticket $ticket, mixed $user): array
    {
        return array_map(
            fn (array $action): array => array_merge($action, [
                'url' => $this->generateUrl('escalated.agent.tickets.custom-action', [
                    'reference' => $ticket->getReference(),
                    'actionKey' => $action['key'],
                ]),
                'method' => 'post',
            ]),
            $this->ticketActions->forTicket($ticket, $user),
        );
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
            'customActions' => $this->customActionsForTicket($ticket, $this->getUser()),
        ]);
    }

    #[Route('/{reference}/actions/{actionKey}', name: 'custom-action', methods: ['POST'])]
    public function customAction(string $reference, string $actionKey, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $user = $this->getUser();
        $action = $this->ticketActions->find($actionKey);

        if (null === $action || !$action->isVisible($ticket, $user)) {
            throw $this->createNotFoundException('Custom action not found.');
        }
        if (!$action->isEnabled($ticket, $user)) {
            throw $this->createAccessDeniedException('Custom action is not enabled.');
        }

        $payload = $request->request->all('payload');

        $this->dispatcher->dispatch(new TicketCustomActionTriggeredEvent(
            $ticket,
            $action->getKey(),
            $user->getUserIdentifier(),
            $payload,
            $action->getMetadata($ticket, $user),
        ));

        $this->addFlash('success', 'Custom action dispatched.');

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
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
            $this->getUser()->getUserIdentifier(),
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

        $agentId = $request->request->get('agent_id');
        $this->assignmentService->assign(
            $ticket,
            $agentId,
            $this->getUser()->getUserIdentifier()
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
            $this->getUser()->getUserIdentifier()
        );

        $this->addFlash('success', 'Ticket status updated.');

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }
}
