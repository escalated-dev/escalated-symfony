<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketLink;
use Escalated\Symfony\Service\TicketLinkService;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of ticket-to-ticket links (problem/incident,
 * parent/child, related). Mirrors the Laravel TicketLinkController.
 */
#[Route('/admin/tickets/{reference}/links', name: 'escalated.admin.ticket_links.')]
class TicketLinkController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketService $ticketService,
        private readonly TicketLinkService $ticketLinks,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $reference): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->requireTicket($reference);

        $links = array_map(
            fn (array $link): array => [
                'id' => $link['id'],
                'link_type' => $link['link_type'],
                'direction' => $link['direction'],
                'ticket' => $this->serializeTicket($link['ticket']),
            ],
            $this->ticketLinks->forTicket($ticket),
        );

        return $this->json(['links' => $links]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->requireTicket($reference);
        $targetReference = (string) $request->request->get('target_reference');
        $linkType = (string) $request->request->get('link_type');

        $target = $this->ticketService->find($targetReference);
        if (null === $target) {
            $this->addFlash('error', 'Target ticket not found.');

            return $this->back($ticket);
        }

        try {
            $this->ticketLinks->link($ticket, $target, $linkType);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->back($ticket);
        }

        $this->addFlash('success', 'Ticket linked successfully.');

        return $this->back($ticket);
    }

    #[Route('/{linkId}', name: 'destroy', methods: ['DELETE'])]
    public function destroy(string $reference, int $linkId): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->requireTicket($reference);

        $link = $this->em->getRepository(TicketLink::class)->find($linkId);
        if (null !== $link) {
            $this->ticketLinks->unlink($link);
        }

        $this->addFlash('success', 'Ticket link removed.');

        return $this->back($ticket);
    }

    private function requireTicket(string $reference): Ticket
    {
        $ticket = $this->ticketService->find($reference);
        if (null === $ticket) {
            throw $this->createNotFoundException('Ticket not found.');
        }

        return $ticket;
    }

    private function back(Ticket $ticket): Response
    {
        return $this->redirectToRoute('escalated.admin.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    /** @return array<string, mixed>|null */
    private function serializeTicket(?Ticket $ticket): ?array
    {
        if (null === $ticket) {
            return null;
        }

        return [
            'id' => $ticket->getId(),
            'reference' => $ticket->getReference(),
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus(),
            'type' => $ticket->getTicketType(),
        ];
    }
}
