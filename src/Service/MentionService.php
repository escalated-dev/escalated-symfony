<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\TicketActivity;

class MentionService
{
    private const MENTION_REGEX = '/@(\w+(?:\.\w+)*)/';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tablePrefix = 'escalated_',
        private readonly ?\Escalated\Symfony\Security\TicketAccess $access = null,
    ) {
    }

    public function processMentions(Reply $reply): array
    {
        $this->access?->assertVisible($reply->getTicket());
        $usernames = $this->extractMentions($reply->getBody());
        if (empty($usernames)) {
            return [];
        }
        $users = $this->findUsers($usernames);
        $mentions = $this->createMentions($reply, $users);
        $this->notifyMentionedUsers($reply, $mentions);

        return $mentions;
    }

    public function extractMentions(string $text): array
    {
        if ('' === trim($text)) {
            return [];
        }
        preg_match_all(self::MENTION_REGEX, $text, $matches);

        return array_unique($matches[1] ?? []);
    }

    public function searchAgents(string $query, int $limit = 10): array
    {
        if ('' === trim($query)) {
            return [];
        }
        $conn = $this->em->getConnection();
        $users = $conn->fetchAllAssociative(
            'SELECT id, email, COALESCE(name, email) as name FROM users WHERE email LIKE ? OR name LIKE ? LIMIT ?',
            ["%{$query}%", "%{$query}%", $limit]
        );

        return array_map(fn ($u) => [
            'id' => $u['id'],
            'name' => $u['name'],
            'email' => $u['email'],
            'username' => explode('@', $u['email'])[0],
        ], $users);
    }

    public function unreadMentions(int|string $userId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            $this->sql('SELECT m.id, m.reply_id, r.ticket_id, t.reference as ticket_reference, t.subject as ticket_subject, m.created_at
             FROM escalated_mentions m
             JOIN escalated_replies r ON m.reply_id = r.id
             JOIN escalated_tickets t ON r.ticket_id = t.id
             WHERE m.user_id = ? AND m.read_at IS NULL AND ('.($this->access?->predicate(\Escalated\Symfony\Entity\Ticket::class, 't') ?? '1 = 1').')
             ORDER BY m.created_at DESC'),
            [$userId]
        );
    }

    public function markAsRead(array $mentionIds, int|string $userId): void
    {
        if (empty($mentionIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, \count($mentionIds), '?'));
        $this->em->getConnection()->executeStatement(
            $this->sql("UPDATE escalated_mentions SET read_at = ? WHERE id IN ({$placeholders}) AND user_id = ? AND reply_id IN (SELECT r.id FROM escalated_replies r JOIN escalated_tickets t ON t.id = r.ticket_id WHERE ".($this->access?->predicate(\Escalated\Symfony\Entity\Ticket::class, 't') ?? '1 = 1').')'),
            array_merge([(new \DateTime())->format('Y-m-d H:i:s')], $mentionIds, [$userId])
        );
    }

    private function findUsers(array $usernames): array
    {
        $conn = $this->em->getConnection();
        $users = [];
        foreach ($usernames as $username) {
            $user = $conn->fetchAssociative(
                'SELECT id, email FROM users WHERE username = ? OR email LIKE ?',
                [$username, "{$username}@%"]
            );
            if ($user) {
                $users[] = $user;
            }
        }

        return $users;
    }

    private function createMentions(Reply $reply, array $users): array
    {
        $conn = $this->em->getConnection();
        $mentions = [];
        foreach ($users as $user) {
            if (null !== $this->access && !$this->access->canReceive($reply->getTicket(), $user['id'])) {
                continue;
            }
            try {
                $conn->insert($this->sql('escalated_mentions'), [
                    'reply_id' => $reply->getId(),
                    'user_id' => $user['id'],
                    'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ]);
                $mentions[] = ['reply_id' => $reply->getId(), 'user_id' => $user['id']];
            } catch (\Throwable) {
                // Duplicate - skip
            }
        }

        return $mentions;
    }

    private function notifyMentionedUsers(Reply $reply, array $mentions): void
    {
        $ticket = $reply->getTicket();
        foreach ($mentions as $mention) {
            $activity = new TicketActivity();
            $activity->setTicket($ticket);
            $activity->setType('mention');
            $activity->setProperties([
                'mentioned_user_id' => $mention['user_id'],
                'reply_id' => $reply->getId(),
                'message' => "You were mentioned in ticket #{$ticket->getReference()}",
            ]);
            $this->em->persist($activity);
        }
        $this->em->flush();
    }

    private function sql(string $sql): string
    {
        return preg_replace('/\bescalated_([a-z_]+)\b/', $this->tablePrefix.'$1', $sql);
    }
}
