<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

/**
 * Resolves the recipient user ids for a ticket's followers.
 *
 * The package abstracts the host user table, so it cannot email follower users
 * itself — these ids are exposed for the host app to deliver to. See issue #67.
 */
final class FollowerRecipients
{
    /**
     * Exclude the actor (a user is never notified of their own action) and
     * de-duplicate, preserving order. Ids are compared as strings so integer
     * and uuid/string host keys both work.
     *
     * @param iterable<int|string> $userIds
     *
     * @return list<string>
     */
    public static function resolve(iterable $userIds, int|string|null $excludeUserId): array
    {
        $exclude = null === $excludeUserId ? null : (string) $excludeUserId;
        $result = [];
        $seen = [];

        foreach ($userIds as $userId) {
            $userId = (string) $userId;

            if ($userId === $exclude || isset($seen[$userId])) {
                continue;
            }

            $seen[$userId] = true;
            $result[] = $userId;
        }

        return $result;
    }
}
