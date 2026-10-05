<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Service\GuestRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Per-client-IP counters behind the guest widget endpoints. Mirrors the
 * guard spec in escalated-dev/escalated-nestjs#130.
 */
final class GuestRateLimiterTest extends TestCase
{
    private ArrayAdapter $pool;

    protected function setUp(): void
    {
        $this->pool = new ArrayAdapter();
    }

    private function limiter(bool $enabled = true, int $tickets = 5, int $replies = 10): GuestRateLimiter
    {
        return new GuestRateLimiter($this->pool, $enabled, $tickets, $replies);
    }

    /**
     * @return list<int|null>
     */
    private function hit(GuestRateLimiter $limiter, string $scope, string $ip, int $times): array
    {
        $out = [];
        for ($i = 0; $i < $times; ++$i) {
            $out[] = $limiter->attempt($scope, $ip);
        }

        return $out;
    }

    public function testAllowsFiveTicketsPerMinutePerIpAndRejectsTheSixth(): void
    {
        $results = $this->hit($this->limiter(), GuestRateLimiter::TICKET, '203.0.113.1', 6);

        self::assertSame([null, null, null, null, null], \array_slice($results, 0, 5));
        self::assertIsInt($results[5]);
        self::assertGreaterThan(0, $results[5]);
        self::assertLessThanOrEqual(60, $results[5]);
    }

    public function testAllowsTenRepliesPerMinutePerIpAndRejectsTheEleventh(): void
    {
        $results = $this->hit($this->limiter(), GuestRateLimiter::REPLY, '203.0.113.1', 11);

        self::assertSame(array_fill(0, 10, null), \array_slice($results, 0, 10));
        self::assertNotNull($results[10]);
    }

    public function testKeysEachClientIpSeparately(): void
    {
        $limiter = $this->limiter();
        $this->hit($limiter, GuestRateLimiter::TICKET, '203.0.113.1', 5);

        self::assertNull($limiter->attempt(GuestRateLimiter::TICKET, '203.0.113.2'));
    }

    public function testCountsTicketsAndRepliesInSeparateBuckets(): void
    {
        $limiter = $this->limiter();
        $this->hit($limiter, GuestRateLimiter::TICKET, '203.0.113.1', 5);

        self::assertNull($limiter->attempt(GuestRateLimiter::REPLY, '203.0.113.1'));
    }

    public function testHonoursConfiguredLimits(): void
    {
        $limiter = $this->limiter(tickets: 2, replies: 1);

        self::assertSame([null, null], $this->hit($limiter, GuestRateLimiter::TICKET, '203.0.113.1', 2));
        self::assertNotNull($limiter->attempt(GuestRateLimiter::TICKET, '203.0.113.1'));
        self::assertNull($limiter->attempt(GuestRateLimiter::REPLY, '203.0.113.1'));
        self::assertNotNull($limiter->attempt(GuestRateLimiter::REPLY, '203.0.113.1'));
    }

    public function testCanBeSwitchedOff(): void
    {
        $results = $this->hit($this->limiter(enabled: false), GuestRateLimiter::TICKET, '203.0.113.1', 20);

        self::assertSame(array_fill(0, 20, null), $results);
    }

    public function testStartsAFreshWindowOnceTheOldOneHasPassed(): void
    {
        $limiter = $this->limiter(tickets: 1);
        $limiter->attempt(GuestRateLimiter::TICKET, '203.0.113.1');

        // Pretend the minute has elapsed (an ArrayAdapter does not honour a
        // past expiry on read, so rewrite the stored window).
        $item = $this->pool->getItem(GuestRateLimiter::key(GuestRateLimiter::TICKET, '203.0.113.1'));
        $item->set(['count' => 1, 'reset' => time() - 1]);
        $this->pool->save($item);

        self::assertNull($limiter->attempt(GuestRateLimiter::TICKET, '203.0.113.1'));
    }

    public function testCountsInTheInjectedPool(): void
    {
        $this->limiter()->attempt(GuestRateLimiter::REPLY, '203.0.113.9');

        $item = $this->pool->getItem(GuestRateLimiter::key(GuestRateLimiter::REPLY, '203.0.113.9'));
        self::assertTrue($item->isHit());
        self::assertSame(1, $item->get()['count']);
    }

    public function testKeysAreValidPsr6Keys(): void
    {
        // PSR-6 reserves {}()/\@: -- an IPv6 address carries colons.
        $key = GuestRateLimiter::key(GuestRateLimiter::TICKET, '2001:db8::1');

        self::assertDoesNotMatchRegularExpression('#[{}()/\\\\@:]#', $key);
        self::assertNull($this->limiter()->attempt(GuestRateLimiter::TICKET, '2001:db8::1'));
    }
}
