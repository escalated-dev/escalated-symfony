<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Escalated\Symfony\Broadcasting\BroadcastableEvent;
use Escalated\Symfony\Broadcasting\BroadcasterInterface;
use Escalated\Symfony\Broadcasting\BroadcastSettings;
use Escalated\Symfony\Entity\AgentNotification;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\CustomerPresence;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketFollower;
use Escalated\Symfony\Entity\TicketRead;
use Escalated\Symfony\Import\ImportContext;
use Escalated\Symfony\Security\TicketAccess;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The agent panel's data (ADR 2026-11-05-agent-panel-data): per-agent unread
 * markers, customer online status, the agent's own status and the agents'
 * in-app notifications. escalated-laravel is the reference implementation.
 */
class AgentPanel
{
    public const STATUSES = ['online', 'away', 'offline'];
    public const PRESENCE_THROTTLE_SECONDS = 30;
    public const NOTIFICATIONS_PER_PAGE = 20;
    public const NOTIFICATIONS_MAX_PER_PAGE = 50;

    /** Reply metadata keys that mark a reply written by the system, not a person. */
    private const SYSTEM_MARKERS = ['workflow_id', 'automation_id', 'system_note'];

    private const TITLES = [
        'ticket_assigned' => '%s was assigned to you',
        'ticket_reply' => 'New reply on %s',
        'mention' => '%2$s mentioned you on %1$s',
        'sla_warning' => 'SLA due soon on %s',
        'sla_breached' => 'SLA breached on %s',
        'chat_transferred' => 'A chat was transferred to you (%s)',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        #[Autowire('%escalated.presence.online_window_seconds%')]
        private readonly int $onlineWindowSeconds = 120,
        #[Autowire('%escalated.agent_notifications%')]
        private readonly bool $notificationsEnabled = true,
        private readonly ?TicketAccess $access = null,
        private readonly ?BroadcasterInterface $broadcaster = null,
        private readonly ?BroadcastSettings $broadcastSettings = null,
        #[Autowire('%escalated.user_class%')]
        private readonly string $userClass = 'App\Entity\User',
    ) {
    }

    // ---- 1. Unread tracking ---------------------------------------------------

    public function markRead(int|string $agentId, Ticket $ticket): void
    {
        $this->writeMarker($agentId, $ticket, new \DateTimeImmutable());
    }

    public function markUnread(int|string $agentId, Ticket $ticket): void
    {
        $this->writeMarker($agentId, $ticket, null);
    }

    private function writeMarker(int|string $agentId, Ticket $ticket, ?\DateTimeImmutable $at): void
    {
        $repository = $this->em->getRepository(TicketRead::class);
        $marker = $repository->findOneBy(['userId' => $agentId, 'ticket' => $ticket]);
        if (null === $marker) {
            $marker = new TicketRead($agentId, $ticket, $at);
            $this->em->persist($marker);
        } else {
            $marker->setLastReadAt($at);
        }
        $this->em->flush();
    }

    public static function isUnread(?\DateTimeImmutable $activity, bool $hasMarker, ?\DateTimeImmutable $lastReadAt): bool
    {
        if ($hasMarker && null === $lastReadAt) {
            return true;
        }
        if (null === $activity) {
            return false;
        }
        if (!$hasMarker) {
            return true;
        }

        return $activity > $lastReadAt;
    }

    /**
     * Whether each ticket is unread for the agent, from one query.
     *
     * @param list<Ticket> $tickets
     *
     * @return array<int, bool> ticket id => is_unread
     */
    public function unreadFlags(int|string $agentId, array $tickets): array
    {
        $markers = [];
        if ([] !== $tickets) {
            $rows = $this->em->createQueryBuilder()
                ->select('IDENTITY(r.ticket) AS ticket_id', 'r.lastReadAt AS last_read_at')
                ->from(TicketRead::class, 'r')
                ->where('r.userId = :agent')
                ->andWhere('r.ticket IN (:tickets)')
                ->setParameter('agent', $agentId)
                ->setParameter('tickets', array_map(static fn (Ticket $t): int => (int) $t->getId(), $tickets))
                ->getQuery()
                ->getArrayResult();
            foreach ($rows as $row) {
                $markers[(int) $row['ticket_id']] = $row['last_read_at'];
            }
        }

        $flags = [];
        foreach ($tickets as $ticket) {
            $id = (int) $ticket->getId();
            $flags[$id] = self::isUnread(
                $ticket->getLastCustomerActivityAt(),
                \array_key_exists($id, $markers),
                $markers[$id] ?? null,
            );
        }

        return $flags;
    }

    /**
     * Limits a ticket query (alias `t`) to the tickets unread for the agent:
     * the rule unreadFlags() applies, as two EXISTS subqueries.
     */
    public static function onlyUnreadQuery(QueryBuilder $qb, int|string $agentId): QueryBuilder
    {
        $class = TicketRead::class;

        return $qb
            ->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %1$s ur1 WHERE ur1.ticket = t AND ur1.userId = :unreadAgent AND ur1.lastReadAt IS NULL) '
                .'OR (t.lastCustomerActivityAt IS NOT NULL AND NOT EXISTS ('
                .'SELECT 1 FROM %1$s ur2 WHERE ur2.ticket = t AND ur2.userId = :unreadAgent AND ur2.lastReadAt >= t.lastCustomerActivityAt))',
                $class,
            ))
            ->setParameter('unreadAgent', $agentId);
    }

    /**
     * A public reply or chat message from the customer side: the requester,
     * a guest (no author) or an inbound email, or a user who is not staff.
     * Agents' replies, internal notes and replies a workflow, automation or
     * the system wrote are not.
     */
    public function isCustomerSide(Reply $reply): bool
    {
        if ($reply->isInternalNote() || 'reply' !== $reply->getType()) {
            return false;
        }
        $metadata = $reply->getMetadata() ?? [];
        foreach (self::SYSTEM_MARKERS as $marker) {
            if (\array_key_exists($marker, $metadata)) {
                return false;
            }
        }
        $authorId = $reply->getAuthorId();
        if (null === $authorId || '' === $authorId || Reply::AUTHOR_INBOUND_EMAIL === $reply->getAuthorClass()) {
            return true;
        }
        $ticket = $reply->getTicket();
        if ((string) $ticket->getRequesterId() === (string) $authorId) {
            return true;
        }

        // Staff are the users with an agent profile (the ESCALATED_AGENT voter).
        return null === $this->em->getRepository(AgentProfile::class)->findOneBy(['userId' => $authorId]);
    }

    /** Records a customer-side reply as activity on its ticket (safe mid-flush). */
    public function replyCreated(Reply $reply): void
    {
        if (ImportContext::isImporting() || !$this->isCustomerSide($reply)) {
            return;
        }
        $at = $reply->getCreatedAt();
        $this->em->getConnection()->update(
            $this->em->getClassMetadata(Ticket::class)->getTableName(),
            ['last_customer_activity_at' => $at],
            ['id' => $reply->getTicket()->getId()],
            ['last_customer_activity_at' => Types::DATETIME_IMMUTABLE],
        );
        $reply->getTicket()->setLastCustomerActivityAt($at);
    }

    // ---- 2. Customer presence ----------------------------------------------------

    public function onlineWindowSeconds(): int
    {
        return max(1, $this->onlineWindowSeconds);
    }

    /**
     * A ticket's customer: its requester, its contact for a guest, else the
     * ticket itself.
     *
     * @return array{0: string, 1: string}|null
     */
    public function presenceSubject(Ticket $ticket): ?array
    {
        $requester = $ticket->getRequesterId();
        if (null !== $requester && '' !== (string) $requester && '0' !== (string) $requester) {
            return [$this->userType($ticket->getRequesterClass()), (string) $requester];
        }
        if (null !== $ticket->getContact()?->getId()) {
            return ['contact', (string) $ticket->getContact()->getId()];
        }
        if (null !== $ticket->getId()) {
            return ['ticket', (string) $ticket->getId()];
        }

        return null;
    }

    /** The host user class a stored requester or a signed-in user is recorded under. */
    private function userType(?string $class): string
    {
        return null === $class || is_a($class, $this->userClass, true) ? $this->userClass : $class;
    }

    /** A signed-in customer-portal user (staff are skipped). */
    public function seeUser(int|string|null $userId, string $userClass): void
    {
        if (null === $userId || '' === (string) $userId) {
            return;
        }
        if (null !== $this->em->getRepository(AgentProfile::class)->findOneBy(['userId' => $userId])) {
            return;
        }
        $this->see([$this->userType($userClass), (string) $userId]);
    }

    public function seeTicketCustomer(?Ticket $ticket): void
    {
        $subject = null !== $ticket ? $this->presenceSubject($ticket) : null;
        if (null !== $subject) {
            $this->see($subject);
        }
    }

    /** Presence is a hint: it never fails the request it rides on. */
    public function quietly(callable $work): void
    {
        try {
            $work();
        } catch (\Throwable) {
            // Not recorded this time.
        }
    }

    /**
     * Throttled in the database: a row is written only when its last write is
     * 30 seconds old, so it holds across processes.
     *
     * @param array{0: string, 1: string} $subject
     */
    private function see(array $subject): void
    {
        [$type, $id] = $subject;
        $now = new \DateTimeImmutable();
        $conn = $this->em->getConnection();
        $table = $this->em->getClassMetadata(CustomerPresence::class)->getTableName();
        $updated = $conn->executeStatement(
            sprintf('UPDATE %s SET last_seen_at = ?, updated_at = ? WHERE subject_type = ? AND subject_id = ? AND last_seen_at <= ?', $table),
            [$now, $now, $type, $id, $now->modify(sprintf('-%d seconds', self::PRESENCE_THROTTLE_SECONDS))],
            [Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE, Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE],
        );
        if ($updated > 0) {
            return;
        }
        $exists = $conn->fetchOne(sprintf('SELECT 1 FROM %s WHERE subject_type = ? AND subject_id = ?', $table), [$type, $id]);
        if (false !== $exists) {
            return;
        }
        try {
            $conn->insert($table, [
                'subject_type' => $type,
                'subject_id' => $id,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], [
                'last_seen_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request recorded them first.
        }
    }

    /**
     * `requester_online` and `requester_last_seen_at` per ticket, from one
     * query. For staff pages only.
     *
     * @param list<Ticket> $tickets
     *
     * @return array<int, array{requester_online: bool, requester_last_seen_at: ?string}>
     */
    public function presence(array $tickets): array
    {
        $subjects = [];
        foreach ($tickets as $ticket) {
            $subject = $this->presenceSubject($ticket);
            if (null !== $subject) {
                $subjects[implode('|', $subject)] = $subject;
            }
        }
        $seen = [];
        if ([] !== $subjects) {
            $qb = $this->em->createQueryBuilder()->select('p')->from(CustomerPresence::class, 'p');
            $or = $qb->expr()->orX();
            $i = 0;
            foreach ($subjects as [$type, $id]) {
                ++$i;
                $or->add(sprintf('(p.subjectType = :type%1$d AND p.subjectId = :id%1$d)', $i));
                $qb->setParameter('type'.$i, $type)->setParameter('id'.$i, $id);
            }
            foreach ($qb->where($or)->getQuery()->getResult() as $row) {
                $seen[$row->getSubjectType().'|'.$row->getSubjectId()] = $row->getLastSeenAt();
            }
        }
        $since = new \DateTimeImmutable(sprintf('-%d seconds', $this->onlineWindowSeconds()));
        $result = [];
        foreach ($tickets as $ticket) {
            $subject = $this->presenceSubject($ticket);
            $last = null !== $subject ? ($seen[implode('|', $subject)] ?? null) : null;
            $result[(int) $ticket->getId()] = [
                'requester_online' => null !== $last && $last >= $since,
                'requester_last_seen_at' => $last?->format(\DATE_ATOM),
            ];
        }

        return $result;
    }

    /**
     * `is_unread` and the customer's presence for agent rows.
     *
     * @param list<Ticket> $tickets
     *
     * @return array<int, array<string, mixed>>
     */
    public function rowFields(int|string $agentId, array $tickets): array
    {
        $flags = $this->unreadFlags($agentId, $tickets);
        $presence = $this->presence($tickets);
        $fields = [];
        foreach ($tickets as $ticket) {
            $id = (int) $ticket->getId();
            $fields[$id] = ['is_unread' => $flags[$id] ?? false, ...$presence[$id]];
        }

        return $fields;
    }

    // ---- 3. The agent's own status ------------------------------------------------

    public function status(int|string $agentId): string
    {
        $profile = $this->em->getRepository(AgentProfile::class)->findOneBy(['userId' => $agentId]);
        $status = $profile?->getChatStatus();

        return \in_array($status, self::STATUSES, true) ? $status : 'offline';
    }

    public function setStatus(int|string $agentId, string $status): void
    {
        $profile = $this->em->getRepository(AgentProfile::class)->findOneBy(['userId' => $agentId]);
        if (null === $profile) {
            $profile = (new AgentProfile())->setUserId($agentId);
            $this->em->persist($profile);
        }
        $profile->setChatStatus($status);
        $this->em->flush();
    }

    // ---- 4. Notifications ---------------------------------------------------------------

    public function notificationsAvailable(): bool
    {
        return $this->notificationsEnabled;
    }

    /**
     * Stores a notification for an agent unless they caused it, and
     * broadcasts it on their channel while broadcasting is on.
     *
     * @param array<string, mixed> $data
     */
    public function notify(int|string|null $agentId, string $type, ?Ticket $ticket, array $data = [], int|string|null $causerId = null): void
    {
        if (!$this->notificationsEnabled || null === $agentId || '' === (string) $agentId || ImportContext::isImporting()) {
            return;
        }
        if (null !== $causerId && (string) $causerId === (string) $agentId) {
            return;
        }
        $row = new AgentNotification($agentId, $type, [
            ...$data,
            'ticket_id' => $ticket?->getId(),
            'reference' => $ticket?->getReference(),
        ]);
        $this->em->persist($row);
        $this->em->flush();

        if (null !== $this->broadcaster && true === $this->broadcastSettings?->isEnabled()) {
            $visible = null !== $ticket && (null === $this->access || $this->access->canReceive($ticket, $agentId));
            $this->broadcaster->broadcast(new BroadcastableEvent(
                type: 'notification.created',
                channel: sprintf('escalated.agents.%s', $agentId),
                payload: $this->item($row, $visible && null !== $ticket ? [(int) $ticket->getId() => $ticket] : []),
            ));
        }
    }

    /**
     * The agent assigned to a ticket, and its followers.
     *
     * @return list<int|string>
     */
    public function replyRecipients(Ticket $ticket): array
    {
        $ids = [];
        if (null !== $ticket->getAssignedTo()) {
            $ids[(string) $ticket->getAssignedTo()] = $ticket->getAssignedTo();
        }
        foreach ($this->em->getRepository(TicketFollower::class)->findBy(['ticketId' => $ticket->getId()]) as $follower) {
            $ids[(string) $follower->getUserId()] = $follower->getUserId();
        }

        return array_values($ids);
    }

    public function unreadCount(int|string $agentId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(AgentNotification::class, 'n')
            ->where('n.userId = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $agentId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Feed items. Ticket lookups go through the request's ticket visibility,
     * so a ticket the agent can no longer view (or a deleted one) is redacted.
     *
     * @param list<AgentNotification> $rows
     *
     * @return list<array<string, mixed>>
     */
    public function present(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = $row->getData()['ticket_id'] ?? null;
            if (is_numeric($id)) {
                $ids[(int) $id] = true;
            }
        }
        $tickets = [];
        if ([] !== $ids) {
            $found = $this->em->getRepository(Ticket::class)->findBy(['id' => array_keys($ids)]);
            foreach ($found as $ticket) {
                if (null === $ticket->getDeletedAt()) {
                    $tickets[(int) $ticket->getId()] = $ticket;
                }
            }
        }

        return array_map(fn (AgentNotification $row): array => $this->item($row, $tickets), $rows);
    }

    /**
     * @param array<int, Ticket> $tickets
     *
     * @return array<string, mixed>
     */
    public function item(AgentNotification $row, array $tickets): array
    {
        $data = $row->getData();
        $ticketId = is_numeric($data['ticket_id'] ?? null) ? (int) $data['ticket_id'] : null;
        $ticket = null !== $ticketId ? ($tickets[$ticketId] ?? null) : null;
        $base = [
            'id' => $row->getId(),
            'type' => $row->getType(),
            'created_at' => $row->getCreatedAt()->format(\DATE_ATOM),
            'read_at' => $row->getReadAt()?->format(\DATE_ATOM),
        ];
        if (null !== $ticketId && null === $ticket) {
            return [...$base, 'title' => 'A ticket you can no longer view', 'body' => null, 'ticket' => null, 'url' => null];
        }
        $reference = $ticket?->getReference() ?? (string) ($data['reference'] ?? '');

        return [
            ...$base,
            'title' => sprintf(self::TITLES[$row->getType()] ?? 'Update on %s', $reference, (string) ($data['mentioned_by'] ?? 'Someone')),
            'body' => $ticket?->getSubject(),
            'ticket' => null !== $ticket ? ['id' => $ticket->getId(), 'reference' => $ticket->getReference(), 'subject' => $ticket->getSubject()] : null,
            'url' => null !== $ticket ? $this->urls->generate('escalated.agent.tickets.show', ['reference' => $ticket->getReference()]) : null,
        ];
    }
}
