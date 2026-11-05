<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Rendering\UserDirectory;

/**
 * @mentions in replies and internal notes, as escalated-laravel's
 * MentionService: `@{Full Name}` (what the composer's mention picker inserts)
 * and `@name` match an agent (a user with an agent profile) by name or email,
 * ignoring case. Each mentioned agent who may see the ticket gets a `mention`
 * notification in the agent feed, and a row in the host's `escalated_mentions`
 * table when it has one.
 */
class MentionService
{
    /** `@{Full Name}`. */
    private const BRACED = '/@\{([^}]+)\}/';

    /** `@name`, outside braces. */
    private const SIMPLE = '/@([a-zA-Z0-9_.-]+)(?![^{]*\})/';

    public const SUGGESTIONS_LIMIT = 10;

    private ?bool $hasMentionsTable = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tablePrefix = 'escalated_',
        private readonly ?\Escalated\Symfony\Security\TicketAccess $access = null,
        private readonly ?UserDirectory $users = null,
        private readonly ?AgentPanel $panel = null,
    ) {
    }

    /**
     * Records and notifies the agents a reply mentions. The author is never
     * notified of their own mention.
     *
     * @return list<array{reply_id: int|null, user_id: int|string}>
     */
    public function processMentions(Reply $reply): array
    {
        $this->access?->assertVisible($reply->getTicket());
        $authorId = $reply->getAuthorId();
        $agents = array_values(array_filter(
            $this->mentionedAgents($reply->getBody()),
            static fn (array $agent) => null === $authorId || (string) $agent['id'] !== (string) $authorId,
        ));
        if ([] === $agents) {
            return [];
        }
        $mentions = $this->createMentions($reply, $agents);
        $this->notifyMentionedUsers($reply, $mentions);

        return $mentions;
    }

    /**
     * The names a body mentions, braced and simple, trimmed and unique.
     *
     * @return list<string>
     */
    public function extractMentions(string $text): array
    {
        if ('' === trim($text)) {
            return [];
        }
        preg_match_all(self::BRACED, $text, $braced);
        preg_match_all(self::SIMPLE, $text, $simple);
        $names = array_filter(
            array_map('trim', [...$braced[1], ...$simple[1]]),
            static fn (string $name) => '' !== $name,
        );

        return array_values(array_unique($names));
    }

    /**
     * The agents a body mentions, by name or email (case-insensitive).
     *
     * @return list<array{id: int|string, name: string, email: string}>
     */
    public function mentionedAgents(string $text): array
    {
        $names = array_map('mb_strtolower', $this->extractMentions($text));
        if ([] === $names || null === $this->users) {
            return [];
        }
        $wanted = array_flip($names);

        return array_values(array_filter(
            $this->users->agents(),
            static fn (array $agent) => isset($wanted[mb_strtolower($agent['name'])])
                || ('' !== $agent['email'] && isset($wanted[mb_strtolower($agent['email'])])),
        ));
    }

    /**
     * The mention picker (escalated-laravel's AgentSearchController): up to
     * `$limit` agents whose name or email contains the query.
     *
     * @return list<array{id: int|string, name: string, email: string}>
     */
    public function searchAgents(string $query, int $limit = self::SUGGESTIONS_LIMIT): array
    {
        $needle = mb_strtolower(trim($query));
        if ('' === $needle || null === $this->users) {
            return [];
        }
        $found = array_filter(
            $this->users->agents(),
            static fn (array $agent) => str_contains(mb_strtolower($agent['name']), $needle)
                || str_contains(mb_strtolower($agent['email']), $needle),
        );

        return array_map(
            static fn (array $agent) => ['id' => $agent['id'], 'name' => $agent['name'], 'email' => $agent['email']],
            \array_slice(array_values($found), 0, $limit),
        );
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

    /**
     * @param list<array{id: int|string, name: string, email: string}> $agents
     *
     * @return list<array{reply_id: int|null, user_id: int|string}>
     */
    private function createMentions(Reply $reply, array $agents): array
    {
        $conn = $this->em->getConnection();
        $mentions = [];
        foreach ($agents as $agent) {
            if (null !== $this->access && !$this->access->canReceive($reply->getTicket(), $agent['id'])) {
                continue;
            }
            // The mentions table is the host's (unreadMentions); without it
            // the notification is the record.
            if ($this->hasMentionsTable()) {
                try {
                    $conn->insert($this->sql('escalated_mentions'), [
                        'reply_id' => $reply->getId(),
                        'user_id' => $agent['id'],
                        'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                    ]);
                } catch (UniqueConstraintViolationException) {
                    continue; // already recorded for this reply
                }
            }
            $mentions[] = ['reply_id' => $reply->getId(), 'user_id' => $agent['id']];
        }

        return $mentions;
    }

    /**
     * @param list<array{reply_id: int|null, user_id: int|string}> $mentions
     */
    private function notifyMentionedUsers(Reply $reply, array $mentions): void
    {
        $mentionedBy = $this->users?->name($reply->getAuthorId()) ?? 'Someone';
        foreach ($mentions as $mention) {
            $this->panel?->notify(
                $mention['user_id'],
                'mention',
                $reply->getTicket(),
                ['reply_id' => $reply->getId(), 'mentioned_by' => $mentionedBy],
                $reply->getAuthorId(),
            );
        }
    }

    private function hasMentionsTable(): bool
    {
        try {
            return $this->hasMentionsTable ??= $this->em->getConnection()->createSchemaManager()->tablesExist([$this->sql('escalated_mentions')]);
        } catch (\Throwable) {
            return $this->hasMentionsTable = false;
        }
    }

    private function sql(string $sql): string
    {
        return preg_replace('/\bescalated_([a-z_]+)\b/', $this->tablePrefix.'$1', $sql);
    }
}
