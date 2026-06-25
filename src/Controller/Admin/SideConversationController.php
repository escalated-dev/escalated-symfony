<?php

declare(strict_types=1);

namespace Escalated\Symfony\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\SideConversation;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\SideConversationService;
use Escalated\Symfony\Service\TicketService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin management of side conversations on a ticket. Mirrors the Laravel
 * SideConversationController (list, create, reply, close).
 */
#[Route('/admin/tickets/{reference}/side-conversations', name: 'escalated.admin.side_conversations.')]
class SideConversationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketService $ticketService,
        private readonly SideConversationService $sideConversations,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(string $reference): JsonResponse
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->requireTicket($reference);

        return $this->json([
            'conversations' => array_map(
                [$this, 'serialize'],
                $this->sideConversations->forTicket($ticket),
            ),
        ]);
    }

    #[Route('', name: 'store', methods: ['POST'])]
    public function store(string $reference, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->requireTicket($reference);
        $user = $this->getUser();

        try {
            $this->sideConversations->create(
                $ticket,
                (string) $request->request->get('subject'),
                (string) $request->request->get('channel'),
                (string) $request->request->get('body'),
                null !== $user ? $user->getUserIdentifier() : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->back($ticket);
        }

        $this->addFlash('success', 'Side conversation created.');

        return $this->back($ticket);
    }

    #[Route('/{id}/reply', name: 'reply', methods: ['POST'])]
    public function reply(string $reference, int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->requireTicket($reference);
        $conversation = $this->requireConversation($id, $ticket);
        $user = $this->getUser();

        try {
            $this->sideConversations->addReply(
                $conversation,
                (string) $request->request->get('body'),
                null !== $user ? $user->getUserIdentifier() : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->back($ticket);
        }

        $this->addFlash('success', 'Reply added.');

        return $this->back($ticket);
    }

    #[Route('/{id}/close', name: 'close', methods: ['POST'])]
    public function close(string $reference, int $id): Response
    {
        $this->denyAccessUnlessGranted('ESCALATED_ADMIN');

        $ticket = $this->requireTicket($reference);
        $conversation = $this->requireConversation($id, $ticket);

        $this->sideConversations->close($conversation);

        $this->addFlash('success', 'Side conversation closed.');

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

    private function requireConversation(int $id, Ticket $ticket): SideConversation
    {
        $conversation = $this->em->getRepository(SideConversation::class)->find($id);
        if (null === $conversation || $conversation->getTicket() !== $ticket) {
            throw $this->createNotFoundException('Side conversation not found.');
        }

        return $conversation;
    }

    private function back(Ticket $ticket): Response
    {
        return $this->redirectToRoute('escalated.admin.tickets.show', [
            'reference' => $ticket->getReference(),
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(SideConversation $conversation): array
    {
        return [
            'id' => $conversation->getId(),
            'subject' => $conversation->getSubject(),
            'channel' => $conversation->getChannel(),
            'status' => $conversation->getStatus(),
            'created_by' => $conversation->getCreatedBy(),
            'created_at' => $conversation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'replies' => array_map(
                static fn ($reply): array => [
                    'id' => $reply->getId(),
                    'body' => $reply->getBody(),
                    'author_id' => $reply->getAuthorId(),
                    'created_at' => $reply->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $conversation->getReplies()->toArray(),
            ),
        ];
    }
}
