<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Widget;

use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\GuestRateLimiter;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Widget\WidgetSettings;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The guest widget endpoints are unauthenticated and every accepted request
 * writes rows and sends mail, so the bundle itself caps them per client IP
 * (ticket creation 5/min, guest replies 10/min by default) rather than relying
 * on each host to put a throttle in front. Mirrors the integration spec in
 * escalated-dev/escalated-nestjs#130.
 */
final class GuestRateLimitTest extends EscalatedWebTestCase
{
    private const TICKETS = '/support/widget/api/tickets';

    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh address per test: cache.app is a filesystem pool shared by
        // every test that compiles the same configuration.
        $this->ip = sprintf('2001:db8::%x:%x', random_int(1, 0xFFFF), random_int(1, 0xFFFF));
    }

    /**
     * @param array<string, mixed> $guestRateLimit
     * @param array<string, mixed> $extensions
     */
    private function client(array $guestRateLimit = [], array $extensions = []): KernelBrowser
    {
        $options = ['extensions' => $extensions];
        if ([] !== $guestRateLimit) {
            $options['escalated'] = ['guest_rate_limit' => $guestRateLimit];
        }

        $client = static::clientWithSchema($options);

        $settings = static::getContainer()->get(WidgetSettings::class);
        \assert($settings instanceof WidgetSettings);
        $settings->setEnabled(true);

        return $client;
    }

    private function createTicket(KernelBrowser $client, int $n): int
    {
        // A distinct email per call, so only the per-IP limit can be what trips.
        $client->request('POST', self::TICKETS, server: [
            'REMOTE_ADDR' => $this->ip,
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode([
            'subject' => 'Help '.$n,
            'description' => 'Something broke.',
            'guest_name' => 'Guest '.$n,
            'guest_email' => 'guest'.$n.'@example.com',
        ]));

        return $client->getResponse()->getStatusCode();
    }

    private function reply(KernelBrowser $client, string $reference, string $token): int
    {
        $client->request('POST', self::TICKETS.'/'.$reference.'/replies', server: [
            'REMOTE_ADDR' => $this->ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GUEST_TOKEN' => $token,
        ], content: (string) json_encode(['body' => 'Any update?']));

        return $client->getResponse()->getStatusCode();
    }

    /**
     * @return list<int>
     */
    private function ticketStatuses(KernelBrowser $client, int $times): array
    {
        $out = [];
        for ($i = 0; $i < $times; ++$i) {
            $out[] = $this->createTicket($client, $i);
        }

        return $out;
    }

    private function guestTicket(): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject('Printer on fire')
            ->setStatus(Ticket::STATUS_OPEN)
            ->setReference('ESC-'.random_int(10000, 99999))
            ->setGuestName('Alice')
            ->setGuestEmail('alice@example.com')
            ->setGuestToken(bin2hex(random_bytes(16)));

        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function rows(string $entity): int
    {
        return (int) static::entityManager()->createQueryBuilder()
            ->select('COUNT(e.id)')->from($entity, 'e')
            ->getQuery()->getSingleScalarResult();
    }

    public function testTheSixthGuestTicketFromOneIpWithinAMinuteGets429(): void
    {
        $client = $this->client();

        self::assertSame([201, 201, 201, 201, 201, 429], $this->ticketStatuses($client, 6));
        self::assertSame(5, $this->rows(Ticket::class));
    }

    public function testARejectedRequestCarriesRetryAfter(): void
    {
        $client = $this->client(['tickets_per_minute' => 1]);
        $this->createTicket($client, 1);

        self::assertSame(429, $this->createTicket($client, 2));
        $retryAfter = (int) $client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(60, $retryAfter);
    }

    public function testTheEleventhGuestReplyFromOneIpWithinAMinuteGets429(): void
    {
        $client = $this->client();
        $ticket = $this->guestTicket();

        $out = [];
        for ($i = 0; $i < 11; ++$i) {
            $out[] = $this->reply($client, (string) $ticket->getReference(), (string) $ticket->getGuestToken());
        }

        self::assertSame(array_merge(array_fill(0, 10, 201), [429]), $out);
        self::assertSame(10, $this->rows(Reply::class));
    }

    public function testRepliesWithAWrongGuestTokenAreCounted(): void
    {
        $client = $this->client(['replies_per_minute' => 2]);
        $ticket = $this->guestTicket();

        $out = [];
        for ($i = 0; $i < 3; ++$i) {
            $out[] = $this->reply($client, (string) $ticket->getReference(), 'wrong-token');
        }

        self::assertSame([404, 404, 429], $out);
    }

    public function testTicketsAndRepliesHaveSeparateCounters(): void
    {
        $client = $this->client(['tickets_per_minute' => 1]);
        $ticket = $this->guestTicket();
        $this->createTicket($client, 1);

        self::assertSame(429, $this->createTicket($client, 2));
        self::assertSame(201, $this->reply($client, (string) $ticket->getReference(), (string) $ticket->getGuestToken()));
    }

    public function testTheLimitComesFromConfiguration(): void
    {
        $client = $this->client(['tickets_per_minute' => 2]);

        self::assertSame([201, 201, 429], $this->ticketStatuses($client, 3));
    }

    public function testAHostThatThrottlesUpstreamCanSwitchItOff(): void
    {
        $client = $this->client(['enabled' => false]);

        self::assertSame(array_fill(0, 8, 201), $this->ticketStatuses($client, 8));
    }

    public function testCountersLiveInTheConfiguredCachePool(): void
    {
        $client = $this->client(
            ['cache_pool' => 'escalated_test.guest_pool'],
            ['framework' => ['cache' => ['pools' => ['escalated_test.guest_pool' => ['adapter' => 'cache.adapter.array', 'public' => true]]]]],
        );

        $this->createTicket($client, 1);

        $pool = static::getContainer()->get('escalated_test.guest_pool');
        \assert($pool instanceof CacheItemPoolInterface);
        self::assertTrue($pool->getItem(GuestRateLimiter::key(GuestRateLimiter::TICKET, $this->ip))->isHit());
    }
}
