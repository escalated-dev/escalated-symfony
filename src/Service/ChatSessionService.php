<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Broadcasting\BroadcastableEvent;
use Escalated\Symfony\Broadcasting\BroadcasterInterface;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Ticket;
use Symfony\Component\Uid\Uuid;

class ChatSessionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketService $ticketService,
        private readonly ChatRoutingService $routingService,
        private readonly BroadcasterInterface $broadcaster,
    ) {
    }

    /**
     * Start a new chat session from the widget.
     *
     * Creates a ticket with channel=chat and status=live, then creates
     * a ChatSession in "waiting" state and attempts auto-routing.
     */
    public function startSession(array $data): array
    {
        $ticket = new Ticket();
        $ticket->setSubject($data['subject'] ?? 'Live Chat');
        $ticket->setDescription($data['message'] ?? '');
        $ticket->setChannel(Ticket::CHANNEL_CHAT);
        $ticket->setStatus(Ticket::STATUS_LIVE);
        $ticket->setGuestName($data['guest_name'] ?? null);
        $ticket->setGuestEmail($data['guest_email'] ?? null);
        $ticket->setGuestToken(Uuid::v4()->toRfc4122());
        $ticket->setChatMetadata([
            'started_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'page_url' => $data['page_url'] ?? null,
        ]);

        $this->em->persist($ticket);
        $this->em->flush();

        $ticket->setReference($ticket->generateReference());
        $this->em->flush();

        $session = new ChatSession();
        $session->setTicket($ticket);
        $session->setVisitorIp($data['visitor_ip'] ?? null);
        $session->setVisitorUserAgent($data['visitor_user_agent'] ?? null);
        $session->setVisitorPageUrl($data['page_url'] ?? null);

        $this->em->persist($session);
        $this->em->flush();

        // Attempt auto-routing
        $agentId = $this->routingService->findAvailableAgent($ticket->getDepartment());
        if (null !== $agentId) {
            $this->assignAgent($session, $agentId);
        }

        $this->broadcaster->broadcast(new BroadcastableEvent(
            'chat.session_started',
            'agents',
            [
                'session_id' => $session->getId(),
                'ticket_id' => $ticket->getId(),
                'ticket_reference' => $ticket->getReference(),
                'guest_name' => $ticket->getGuestName(),
                'status' => $session->getStatus(),
            ]
        ));

        return [
            'ticket' => $ticket,
            'session' => $session,
        ];
    }

    /**
     * Assign an agent to a chat session.
     */
    public function assignAgent(ChatSession $session, int $agentId): ChatSession
    {
        $session->setAgentId($agentId);
        $session->setStatus(ChatSession::STATUS_ACTIVE);
        $session->setAgentJoinedAt(new \DateTimeImmutable());

        $ticket = $session->getTicket();
        $ticket->setAssignedTo($agentId);

        $this->em->flush();

        $this->broadcaster->broadcast(new BroadcastableEvent(
            'chat.agent_joined',
            sprintf('ticket.%d', $ticket->getId()),
            [
                'session_id' => $session->getId(),
                'agent_id' => $agentId,
            ]
        ));

        return $session;
    }

    /**
     * Send a chat message (creates a reply on the ticket).
     */
    public function sendMessage(ChatSession $session, string $body, ?int $authorId = null, ?string $authorClass = null): void
    {
        $ticket = $session->getTicket();
        $isAgent = null !== $authorClass;

        $reply = $this->ticketService->addReply(
            $ticket,
            $authorId ?? 0,
            $body,
            false,
            $authorClass
        );

        $session->setLastActivityAt(new \DateTimeImmutable());
        $this->em->flush();

        $this->broadcaster->broadcast(new BroadcastableEvent(
            'chat.message',
            sprintf('ticket.%d', $ticket->getId()),
            [
                'session_id' => $session->getId(),
                'reply_id' => $reply->getId(),
                'body' => $reply->getBody(),
                'is_agent' => $isAgent,
                'created_at' => $reply->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ]
        ));
    }

    /**
     * End a chat session.
     */
    public function endSession(ChatSession $session, ?int $causerId = null): ChatSession
    {
        $now = new \DateTimeImmutable();
        $session->setStatus(ChatSession::STATUS_ENDED);
        $session->setEndedAt($now);

        $ticket = $session->getTicket();
        $ticket->setChatEndedAt($now);
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $chatMeta = $ticket->getChatMetadata() ?? [];
        $chatMeta['ended_at'] = $now->format(\DateTimeInterface::ATOM);
        $chatMeta['ended_by'] = $causerId;
        if (null !== $session->getAgentJoinedAt()) {
            $chatMeta['duration_seconds'] = $now->getTimestamp() - $session->getAgentJoinedAt()->getTimestamp();
        }
        $ticket->setChatMetadata($chatMeta);

        $this->em->flush();

        $this->broadcaster->broadcast(new BroadcastableEvent(
            'chat.session_ended',
            sprintf('ticket.%d', $ticket->getId()),
            [
                'session_id' => $session->getId(),
                'ticket_id' => $ticket->getId(),
                'ended_by' => $causerId,
            ]
        ));

        return $session;
    }

    /**
     * Find a chat session by ticket ID.
     */
    public function findByTicket(int $ticketId): ?ChatSession
    {
        return $this->em->getRepository(ChatSession::class)->findOneBy(['ticket' => $ticketId]);
    }

    /**
     * List active/waiting chat sessions for agents.
     */
    public function listActiveSessions(): array
    {
        return $this->em->getRepository(ChatSession::class)
            ->createQueryBuilder('s')
            ->where('s.status IN (:statuses)')
            ->setParameter('statuses', [ChatSession::STATUS_WAITING, ChatSession::STATUS_ACTIVE])
            ->orderBy('s.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Close idle sessions that have had no activity for the given threshold.
     */
    public function closeIdleSessions(int $idleMinutes = 30): int
    {
        $threshold = new \DateTimeImmutable(sprintf('-%d minutes', $idleMinutes));
        $sessions = $this->em->getRepository(ChatSession::class)
            ->createQueryBuilder('s')
            ->where('s.status IN (:statuses)')
            ->andWhere('s.lastActivityAt < :threshold')
            ->setParameter('statuses', [ChatSession::STATUS_WAITING, ChatSession::STATUS_ACTIVE])
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($sessions as $session) {
            $this->endSession($session);
            ++$count;
        }

        return $count;
    }

    /**
     * Mark abandoned sessions (waiting too long without an agent).
     */
    public function markAbandonedSessions(int $waitMinutes = 10): int
    {
        $threshold = new \DateTimeImmutable(sprintf('-%d minutes', $waitMinutes));
        $sessions = $this->em->getRepository(ChatSession::class)
            ->createQueryBuilder('s')
            ->where('s.status = :status')
            ->andWhere('s.createdAt < :threshold')
            ->setParameter('status', ChatSession::STATUS_WAITING)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($sessions as $session) {
            $session->setStatus(ChatSession::STATUS_ABANDONED);
            $session->setEndedAt(new \DateTimeImmutable());

            $ticket = $session->getTicket();
            $ticket->setStatus(Ticket::STATUS_OPEN);
            $ticket->setChatEndedAt(new \DateTimeImmutable());

            $this->broadcaster->broadcast(new BroadcastableEvent(
                'chat.session_abandoned',
                sprintf('ticket.%d', $ticket->getId()),
                ['session_id' => $session->getId()]
            ));

            ++$count;
        }

        $this->em->flush();

        return $count;
    }
}
