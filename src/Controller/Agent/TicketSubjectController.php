<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\TicketSubjectService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/agent/tickets', name: 'escalated.agent.tickets.')]
class TicketSubjectController extends AbstractController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly TicketSubjectService $ticketSubjectService,
    ) {
    }

    #[Route('/{reference}/subjects', name: 'subjects.attach', methods: ['POST'])]
    public function attach(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $type = $request->request->getString('type');
        $id = $request->request->getString('id');
        if ('' === $type || '' === $id) {
            $this->addFlash('error', 'Subject type and id are required.');

            return $this->redirectToRoute('escalated.agent.tickets.show', [
                'reference' => $ticket->getReference(),
            ]);
        }

        try {
            $this->ticketSubjectService->assertApiTypeAllowed($type);
            $this->ticketSubjectService->attach($ticket, $type, $id, $request->request->get('role'));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('escalated.agent.tickets.show', [
                'reference' => $ticket->getReference(),
            ]);
        }

        $this->addFlash('success', 'Subject attached.');

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    #[Route('/{reference}/subjects/{linkId}', name: 'subjects.detach', requirements: ['linkId' => '\d+'], methods: ['DELETE'])]
    public function detach(string $reference, int $linkId): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        try {
            $this->ticketSubjectService->detach($ticket, $linkId);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('escalated.agent.tickets.show', [
                'reference' => $ticket->getReference(),
            ]);
        }

        $this->addFlash('success', 'Subject detached.');

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }
}
