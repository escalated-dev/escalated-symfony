<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Per-client-IP rate limit for the unauthenticated guest widget endpoints.
 *
 * Every accepted guest ticket or reply writes rows and sends outbound mail, so
 * an uncapped endpoint lets anyone flood the helpdesk and the mail provider.
 * Two scopes, each with its own fixed 60-second window per client IP:
 *   - ticket: POST {route_prefix}/widget/api/tickets (default 5 per minute)
 *   - reply:  POST {route_prefix}/widget/api/tickets/{reference}/replies
 *             (default 10 per minute), checked before the guest token so
 *             wrong-token requests count too.
 *
 * Configured under `escalated.guest_rate_limit`. Counters live in a PSR-6 pool,
 * `cache.app` by default; a multi-server deployment should point `cache_pool`
 * at a shared pool (Redis, Memcached).
 *
 * The client IP is Request::getClientIp(). Behind a reverse proxy or load
 * balancer the host must configure `framework.trusted_proxies` (and
 * `trusted_headers`), or every guest shares the proxy's address.
 */
final class GuestRateLimiter
{
    public const TICKET = 'ticket';
    public const REPLY = 'reply';

    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly bool $enabled = true,
        private readonly int $ticketsPerMinute = 5,
        private readonly int $repliesPerMinute = 10,
    ) {
    }

    /**
     * Count one request against a scope's per-IP window.
     *
     * @return int|null null when allowed, otherwise the seconds until the window resets
     */
    public function attempt(string $scope, string $ip): ?int
    {
        if (!$this->enabled) {
            return null;
        }

        $limit = self::TICKET === $scope ? $this->ticketsPerMinute : $this->repliesPerMinute;
        $now = time();

        $item = $this->cache->getItem(self::key($scope, $ip));
        $bucket = $item->isHit() ? $item->get() : null;
        if (!\is_array($bucket) || (int) ($bucket['reset'] ?? 0) <= $now) {
            $bucket = ['count' => 0, 'reset' => $now + self::WINDOW_SECONDS];
        }

        $retryAfter = max(1, (int) $bucket['reset'] - $now);

        if ((int) $bucket['count'] >= $limit) {
            return $retryAfter;
        }

        $bucket['count'] = (int) $bucket['count'] + 1;
        $item->set($bucket);
        $item->expiresAfter($retryAfter);
        $this->cache->save($item);

        return null;
    }

    /**
     * Cache key for a scope and client IP. Hashed, because PSR-6 reserves the
     * colon an IPv6 address carries.
     */
    public static function key(string $scope, string $ip): string
    {
        return 'escalated_guest_'.$scope.'_'.hash('sha256', $ip);
    }
}
