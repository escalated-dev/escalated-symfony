<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Agent;

use Escalated\Symfony\Service\SnoozeService;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/agent/tickets', name: 'escalated.agent.tickets.')]
class TicketSnoozeController extends AbstractController
{
    public function __construct(
        private readonly TicketService $ticketService,
        private readonly SnoozeService $snoozeService,
    ) {
    }

    #[Route('/{reference}/snooze', name: 'snooze', methods: ['POST'])]
    public function snooze(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        $untilStr = $request->request->get('snoozed_until');
        if (null === $untilStr || '' === $untilStr) {
            $this->addFlash('error', 'Snooze time is required.');

            return $this->redirectToRoute('escalated.agent.tickets.show', [
                'reference' => $ticket->getReference(),
            ]);
        }

        try {
            $until = new \DateTimeImmutable($untilStr);
            $this->snoozeService->snooze(
                $ticket,
                $until,
                (int) $this->getUser()->getUserIdentifier(),
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('escalated.agent.tickets.show', [
                'reference' => $ticket->getReference(),
            ]);
        }

        $this->addFlash('success', sprintf('Ticket snoozed until %s.', $until->format('Y-m-d H:i')));

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    #[Route('/{reference}/unsnooze', name: 'unsnooze', methods: ['POST'])]
    public function unsnooze(string $reference): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_AGENT');

        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        try {
            $this->snoozeService->unsnooze(
                $ticket,
                (int) $this->getUser()->getUserIdentifier(),
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('escalated.agent.tickets.show', [
                'reference' => $ticket->getReference(),
            ]);
        }

        $this->addFlash('success', 'Ticket unsnoozed.');

        return $this->redirectToRoute('escalated.agent.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }
}
