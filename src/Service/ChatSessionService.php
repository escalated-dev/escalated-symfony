<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Broadcasting\BroadcastableEvent;
use Escalated\Symfony\Broadcasting\BroadcasterInterface;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ChatSessionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketService $ticketService,
        private readonly ChatRoutingService $routingService,
        private readonly BroadcasterInterface $broadcaster,
        private readonly ?\Escalated\Symfony\Security\TicketAccess $access = null,
        private readonly ?\Escalated\Symfony\Broadcasting\TicketBroadcastDelivery $delivery = null,
        private readonly ?EventDispatcherInterface $events = null,
    ) {
    }

    /**
     * Start a new chat session from the widget.
     *
     * Creates a ticket with channel=chat and status=live, then creates
     * a ChatSession in "waiting" state and attempts auto-routing.
     *
     * `metadata` holds host parameters from an embedding page; callers must
     * pass them through {@see \Escalated\Symfony\Widget\WidgetMetadata::sanitize()}.
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
        if (($data['department'] ?? null) instanceof \Escalated\Symfony\Entity\Department) {
            $ticket->setDepartment($data['department']);
        }
        // Host parameters (already sanitised by the caller) first, so they
        // cannot replace the server's own keys.
        $ticket->setChatMetadata(array_merge(\is_array($data['metadata'] ?? null) ? $data['metadata'] : [], [
            'started_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'page_url' => $data['page_url'] ?? null,
        ]));

        $this->em->persist($ticket);
        $this->em->flush();

        $ticket->setReference($ticket->generateReference());
        $this->em->flush();

        $session = new ChatSession();
        $session->setTicket($ticket);
        $session->setVisitorIp($data['visitor_ip'] ?? null);
        $session->setVisitorUserAgent($data['visitor_user_agent'] ?? null);
        $session->setVisitorPageUrl(isset($data['page_url']) ? mb_substr((string) $data['page_url'], 0, 255) : null);

        $this->em->persist($session);
        $this->em->flush();

        $this->announce(EventNames::CHAT_STARTED, $session);

        // Attempt auto-routing
        $agentId = $this->routingService->findAvailableAgent($ticket->getDepartment(), $ticket);
        if (null !== $agentId) {
            $this->assignAgent($session, $agentId);
        }

        ($this->delivery ?? $this->broadcaster)->broadcast(new BroadcastableEvent(
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
    public function assignAgent(ChatSession $session, int|string $agentId): ChatSession
    {
        $session->setAgentId($agentId);
        $session->setStatus(ChatSession::STATUS_ACTIVE);
        $session->setAgentJoinedAt(new \DateTimeImmutable());

        $ticket = $session->getTicket();
        $this->access?->assertVisible($ticket);
        $this->access?->assertAssignable($ticket, $agentId);
        $ticket->setAssignedTo($agentId);

        $this->em->flush();

        $this->announce(EventNames::CHAT_ASSIGNED, $session, ['agent_id' => $agentId]);

        ($this->delivery ?? $this->broadcaster)->broadcast(new BroadcastableEvent(
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
     * Hand a chat to another agent and/or department, as escalated-laravel's
     * ChatSessionService::transferChat. With an agent, the session and its
     * ticket are reassigned to that agent. With a department, the ticket moves
     * there; without an agent too, the chat goes back to the queue
     * (unassigned, waiting) and routing picks an agent in its new department.
     * Emits `chat.transferred` with from_agent_id, to_agent_id and
     * to_department_id.
     *
     * @throws \InvalidArgumentException when neither target is given, the
     *                                   chat is over, or the agent cannot
     *                                   access the chat's ticket
     */
    public function transferChat(ChatSession $session, int|string|null $toAgentId = null, ?\Escalated\Symfony\Entity\Department $toDepartment = null): ChatSession
    {
        if (null === $toAgentId && null === $toDepartment) {
            throw new \InvalidArgumentException('Provide an agent_id or department_id.');
        }
        if ($this->isOver($session)) {
            throw new \InvalidArgumentException('Chat is already ended.');
        }

        $ticket = $session->getTicket();
        $fromAgentId = $session->getAgentId();

        if (null !== $toDepartment) {
            $ticket->setDepartment($toDepartment);
        }
        if (null !== $toAgentId && null !== $this->access && !$this->access->canAssign($ticket, $toAgentId)) {
            if (null !== $toDepartment) {
                $this->em->refresh($ticket);
            }
            throw new \InvalidArgumentException('Agent cannot access this ticket.');
        }

        if (null !== $toAgentId) {
            $session->setAgentId($toAgentId);
            $ticket->setAssignedTo($toAgentId);
        } else {
            $session->setAgentId(null);
            $session->setStatus(ChatSession::STATUS_WAITING);
            $ticket->setAssignedTo(null);
        }
        $this->em->flush();

        $transfer = [
            'from_agent_id' => $fromAgentId,
            'to_agent_id' => $toAgentId,
            'to_department_id' => $toDepartment?->getId(),
        ];
        $this->announce(EventNames::CHAT_TRANSFERRED, $session, $transfer);

        ($this->delivery ?? $this->broadcaster)->broadcast(new BroadcastableEvent(
            'chat.transferred',
            sprintf('ticket.%d', $ticket->getId()),
            ['session_id' => $session->getId(), ...$transfer]
        ));

        if (null === $toAgentId) {
            $agentId = $this->routingService->findAvailableAgent($toDepartment, $ticket);
            if (null !== $agentId) {
                $this->assignAgent($session, $agentId);
            }
        }

        return $session;
    }

    /**
     * Send a chat message (creates a reply on the ticket).
     */
    public function sendMessage(ChatSession $session, string $body, int|string|null $authorId = null, ?string $authorClass = null): void
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

        $this->announce(EventNames::CHAT_MESSAGE, $session, ['reply_id' => $reply->getId(), 'is_agent' => $isAgent]);

        ($this->delivery ?? $this->broadcaster)->broadcast(new BroadcastableEvent(
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
     * The visitor is typing: tells the agents watching the ticket.
     */
    public function customerTyping(ChatSession $session): void
    {
        $ticket = $session->getTicket();

        ($this->delivery ?? $this->broadcaster)->broadcast(new BroadcastableEvent(
            'chat.typing',
            sprintf('ticket.%d', $ticket->getId()),
            [
                'session_id' => $session->getId(),
                'is_agent' => false,
            ]
        ));
    }

    /**
     * Whether a session is over (ended by someone, or abandoned in the queue).
     */
    public function isOver(ChatSession $session): bool
    {
        return \in_array($session->getStatus(), [ChatSession::STATUS_ENDED, ChatSession::STATUS_ABANDONED], true);
    }

    /**
     * Record the visitor's 1-5 rating of an ended chat in the ticket's chat
     * metadata.
     *
     * @throws \InvalidArgumentException when the chat is not over, the score
     *                                   is out of range, or it is already rated
     */
    public function rateSession(ChatSession $session, int $rating, ?string $comment = null): void
    {
        if (!$this->isOver($session)) {
            throw new \InvalidArgumentException('Chat must be ended before rating.');
        }
        if ($rating < 1 || $rating > 5) {
            throw new \InvalidArgumentException('Rating must be an integer between 1 and 5.');
        }

        $ticket = $session->getTicket();
        $chatMeta = $ticket->getChatMetadata() ?? [];
        if (isset($chatMeta['rating'])) {
            throw new \InvalidArgumentException('This chat has already been rated.');
        }

        $chatMeta['rating'] = $rating;
        $chatMeta['rating_comment'] = null !== $comment && '' !== $comment ? mb_substr($comment, 0, 1000) : null;
        $ticket->setChatMetadata($chatMeta);
        $this->em->flush();
    }

    /**
     * End a chat session.
     */
    public function endSession(ChatSession $session, int|string|null $causerId = null): ChatSession
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

        $this->announce(EventNames::CHAT_ENDED, $session, ['ended_by' => $causerId]);

        ($this->delivery ?? $this->broadcaster)->broadcast(new BroadcastableEvent(
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

            ($this->delivery ?? $this->broadcaster)->broadcast(new BroadcastableEvent(
                'chat.session_abandoned',
                sprintf('ticket.%d', $ticket->getId()),
                ['session_id' => $session->getId()]
            ));

            ++$count;
        }

        $this->em->flush();

        foreach ($sessions as $session) {
            $this->announce(EventNames::CHAT_ENDED, $session, ['reason' => 'abandoned']);
        }

        return $count;
    }

    /**
     * Announces a chat event to webhooks and workflows through the dispatch
     * tickets use, under its canonical name (chat.started, chat.assigned,
     * chat.transferred, chat.message, chat.ended). The payload carries the session's id, never
     * the visitor's chat token. Realtime broadcasts keep their own names.
     *
     * @param array<string, mixed> $context
     */
    private function announce(string $name, ChatSession $session, array $context = []): void
    {
        $this->events?->dispatch(new TicketWorkflowEvent(
            $name,
            $session->getTicket(),
            ['chat_session_id' => $session->getId(), ...$context],
        ));
    }
}
