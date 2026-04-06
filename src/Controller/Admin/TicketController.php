<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Escalated\Symfony\Rendering\UiRendererInterface;
use Escalated\Symfony\Service\AssignmentService;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/tickets', name: 'escalated.admin.tickets.')]
class TicketController extends AbstractController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly AssignmentService $assignmentService,
        private readonly UiRendererInterface $renderer,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $tickets = $this->ticketService->list($request->query->all());

        return $this->renderer->render('Escalated/Admin/Tickets/Index', [
            'tickets' => $tickets,
            'filters' => $request->query->all(),
        ]);
    }

    #[Route('/{reference}', name: 'show', methods: ['GET'])]
    public function show(string $reference): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->ticketService->find($reference);
        if ($ticket === null) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        return $this->renderer->render('Escalated/Admin/Tickets/Show', [
            'ticket' => $ticket,
        ]);
    }

    #[Route('/{reference}', name: 'update', methods: ['PATCH'])]
    public function update(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->ticketService->find($reference);
        if ($ticket === null) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $this->ticketService->update($ticket, $request->request->all());

        $this->addFlash('success', 'Ticket updated.');

        return $this->redirectToRoute('escalated.admin.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    #[Route('/{reference}/assign', name: 'assign', methods: ['POST'])]
    public function assign(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->ticketService->find($reference);
        if ($ticket === null) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $agentId = (int) $request->request->get('agent_id');
        $this->assignmentService->assign(
            $ticket,
            $agentId,
            (int) $this->getUser()->getUserIdentifier()
        );

        $this->addFlash('success', 'Ticket assigned.');

        return $this->redirectToRoute('escalated.admin.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    #[Route('/{reference}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $reference): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->ticketService->find($reference);
        if ($ticket === null) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        // Soft delete
        $ticket->setDeletedAt(new \DateTimeImmutable());
        // Flush is handled by Doctrine auto-flush or explicit flush in service

        $this->addFlash('success', 'Ticket deleted.');

        return $this->redirectToRoute('escalated.admin.tickets.index');
    }
}
