<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Widget;

use Escalated\Symfony\Entity\GuestVerification;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\ObtainsGuestProof;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Verified guest access for the widget (escalated-laravel docs/guest-access.md,
 * escalated-developer-context domain-model/guest-policy.md).
 *
 * Before: anyone could open a widget ticket or a live chat under any email
 * address, and a ticket was read with a permanent token. Status lookup was
 * switched off because nothing proved the guest owned the mailbox.
 */
final class VerifiedGuestAccessTest extends EscalatedWebTestCase
{
    use ObtainsGuestProof;

    private const API = '/support/widget/api';

    private string $ip;

    protected function setUp(): void
    {
        $this->ip = sprintf('2001:db8:aa::%x:%x', random_int(1, 0xFFFF), random_int(1, 0xFFFF));
    }

    public function testConfigAdvertisesVerificationAndStatusLookup(): void
    {
        $client = $this->client();

        $config = $this->api($client, 'GET', '/config');

        self::assertTrue($config['guest_verification_required']);
        self::assertTrue($config['status_lookup_enabled']);
    }

    public function testACodeIsEmailedAndNeverReturned(): void
    {
        $client = $this->client();

        $body = $this->api($client, 'POST', '/verification', ['email' => ' Ada@Example.com ', 'purpose' => 'ticket']);

        self::assertSame(202, $this->code($client));
        self::assertIsString($body['verification_id']);
        self::assertSame(600, $body['expires_in']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
        $code = self::lastCodeSentTo('ada@example.com');
        self::assertMatchesRegularExpression('/^\d{8}$/', $code);
        self::assertStringNotContainsString($code, (string) $client->getResponse()->getContent());

        $stored = static::entityManager()->find(GuestVerification::class, $body['verification_id']);
        self::assertNotNull($stored);
        self::assertStringNotContainsString($code, $stored->getCodeHash());
        self::assertStringNotContainsString('ada', $stored->getEmailDigest());
    }

    public function testVerificationRequestsAreValidated(): void
    {
        $client = $this->client();

        $body = $this->api($client, 'POST', '/verification', ['email' => 'not-an-email', 'purpose' => 'ticket']);
        self::assertSame(422, $this->code($client));
        self::assertArrayHasKey('email', $body['errors']);

        $body = $this->api($client, 'POST', '/verification', ['email' => 'ada@example.com', 'purpose' => 'admin']);
        self::assertSame(422, $this->code($client));
        self::assertArrayHasKey('purpose', $body['errors']);
    }

    public function testCodeDeliveryIsBudgetedPerClientAndPerMailbox(): void
    {
        $client = $this->client(['guest_access' => ['codes_per_client_per_hour' => 2, 'codes_per_mailbox_per_hour' => 3]]);

        foreach ([1, 2] as $_) {
            $this->api($client, 'POST', '/verification', ['email' => 'ada@example.com', 'purpose' => 'ticket']);
            self::assertSame(202, $this->code($client));
        }
        $this->api($client, 'POST', '/verification', ['email' => 'ada@example.com', 'purpose' => 'ticket']);
        self::assertSame(429, $this->code($client));
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));

        // Another client is not locked out by the first one...
        $this->ip = '2001:db8:bb::1';
        $this->api($client, 'POST', '/verification', ['email' => 'ada@example.com', 'purpose' => 'lookup']);
        self::assertSame(202, $this->code($client));
        // ...but the mailbox as a whole has its own budget.
        $this->ip = '2001:db8:bb::2';
        $this->api($client, 'POST', '/verification', ['email' => 'ADA@example.com', 'purpose' => 'chat']);
        self::assertSame(429, $this->code($client));
    }

    public function testATicketNeedsAProof(): void
    {
        $client = $this->client();

        $body = $this->api($client, 'POST', '/tickets', $this->ticket());

        self::assertSame(422, $this->code($client));
        self::assertArrayHasKey('verification_code', $body['errors']);
        self::assertSame(0, static::entityManager()->getRepository(Ticket::class)->count([]));
    }

    public function testAVerifiedTicketGetsAnExpiringGrantThatReadsAndRepliesToIt(): void
    {
        $client = $this->client();

        $created = $this->api($client, 'POST', '/tickets', $this->ticket() + self::guestProof($client, 'ada@example.com'));

        self::assertSame(201, $this->code($client));
        $grant = $created['guest_access_token'];
        $ticket = $this->find($created['reference']);
        self::assertNotSame($ticket->getGuestToken(), $grant);
        self::assertSame('ada@example.com', $ticket->getGuestVerifiedEmail());
        self::assertNotSame($grant, $ticket->getGuestAccessHash(), 'the grant is stored only as a digest');
        $expires = new \DateTimeImmutable($created['expires_at']);
        self::assertEqualsWithDelta((new \DateTimeImmutable('+1440 minutes'))->getTimestamp(), $expires->getTimestamp(), 5);

        $status = $this->api($client, 'GET', '/tickets/'.$created['reference'], null, $grant);
        self::assertSame(200, $this->code($client));
        self::assertSame('Printer on fire', $status['subject']);
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $this->api($client, 'POST', '/tickets/'.$created['reference'].'/replies', ['body' => 'Any news?'], $grant);
        self::assertSame(201, $this->code($client));

        // The permanent token a ticket still carries does not open it.
        $this->api($client, 'GET', '/tickets/'.$created['reference'], null, (string) $ticket->getGuestToken());
        self::assertSame(404, $this->code($client));
    }

    public function testACodeIsSingleUseAndBoundToEmailAndPurpose(): void
    {
        $client = $this->client();

        $proof = self::guestProof($client, 'ada@example.com');
        $this->api($client, 'POST', '/tickets', $this->ticket(['email' => 'eve@example.com']) + $proof);
        self::assertSame(422, $this->code($client), 'a proof for another email');

        $this->api($client, 'POST', '/tickets', $this->ticket() + self::guestProof($client, 'ada@example.com', 'chat'));
        self::assertSame(422, $this->code($client), 'a proof for another purpose');

        $proof = self::guestProof($client, 'ada@example.com');
        $this->api($client, 'POST', '/tickets', $this->ticket() + $proof);
        self::assertSame(201, $this->code($client));
        $this->api($client, 'POST', '/tickets', $this->ticket() + $proof);
        self::assertSame(422, $this->code($client), 'a used proof');
    }

    public function testFiveWrongGuessesBurnTheCode(): void
    {
        $client = $this->client();
        $proof = self::guestProof($client, 'ada@example.com');
        $wrong = '00000000' === $proof['verification_code'] ? '11111111' : '00000000';

        foreach (range(1, 5) as $guess) {
            // Each guess from its own address: the per-IP ticket limit is not what stops them.
            $this->ip = '2001:db8:cc::'.$guess;
            $this->api($client, 'POST', '/tickets', $this->ticket() + ['verification_code' => $wrong] + $proof);
            self::assertSame(422, $this->code($client));
        }
        $this->ip = '2001:db8:cc::99';
        $this->api($client, 'POST', '/tickets', $this->ticket() + $proof);

        self::assertSame(422, $this->code($client));
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        $client = $this->client();
        $proof = self::guestProof($client, 'ada@example.com');
        $em = static::entityManager();
        $em->find(GuestVerification::class, $proof['verification_id'])?->setExpiresAt(new \DateTimeImmutable('-1 second'));
        $em->flush();

        $this->api($client, 'POST', '/tickets', $this->ticket() + $proof);

        self::assertSame(422, $this->code($client));
    }

    public function testLookupIssuesAFreshGrantAndRetiresTheOldOne(): void
    {
        $client = $this->client();
        $created = $this->api($client, 'POST', '/tickets', $this->ticket() + self::guestProof($client, 'ada@example.com'));
        $first = $created['guest_access_token'];

        $found = $this->api($client, 'POST', '/lookup', [
            'email' => 'ADA@example.com',
            'reference' => $created['reference'],
        ] + self::guestProof($client, 'ada@example.com', 'lookup'));

        self::assertSame(200, $this->code($client));
        self::assertCount(1, $found['data']);
        $match = $found['data'][0];
        self::assertSame($created['reference'], $match['reference']);
        self::assertSame('Printer on fire', $match['subject']);
        self::assertNotSame($first, $match['guest_access_token']);

        $this->api($client, 'GET', '/tickets/'.$created['reference'], null, $match['guest_access_token']);
        self::assertSame(200, $this->code($client));
        $this->api($client, 'GET', '/tickets/'.$created['reference'], null, $first);
        self::assertSame(404, $this->code($client), 'the earlier grant was rotated out');
    }

    public function testLookupNeedsTheTicketsOwnEmailAndConsumesTheProofAnyway(): void
    {
        $client = $this->client();
        $created = $this->api($client, 'POST', '/tickets', $this->ticket() + self::guestProof($client, 'ada@example.com'));

        $proof = self::guestProof($client, 'eve@example.com', 'lookup');
        $found = $this->api($client, 'POST', '/lookup', ['email' => 'eve@example.com', 'reference' => $created['reference']] + $proof);
        self::assertSame(200, $this->code($client));
        self::assertSame([], $found['data']);

        $this->api($client, 'POST', '/lookup', ['email' => 'eve@example.com', 'reference' => $created['reference']] + $proof);
        self::assertSame(422, $this->code($client));

        $this->api($client, 'POST', '/lookup', ['email' => 'ada@example.com', 'reference' => $created['reference']]);
        self::assertSame(422, $this->code($client), 'lookup without a proof');
    }

    public function testForgedTruncatedOrMisdirectedGrantsOpenNothing(): void
    {
        $client = $this->client();
        $a = $this->api($client, 'POST', '/tickets', $this->ticket() + self::guestProof($client, 'ada@example.com'));
        $b = $this->api($client, 'POST', '/tickets', $this->ticket(['subject' => 'Other']) + self::guestProof($client, 'ada@example.com'));

        foreach ([
            substr($a['guest_access_token'], 0, -4),
            strrev($a['guest_access_token']),
            'not-a-grant',
        ] as $token) {
            $this->api($client, 'GET', '/tickets/'.$a['reference'], null, $token);
            self::assertSame(404, $this->code($client));
        }

        $this->api($client, 'GET', '/tickets/'.$b['reference'], null, $a['guest_access_token']);
        self::assertSame(404, $this->code($client), 'a grant for another ticket');
    }

    public function testChangingTheGuestEmailOrExpiryRevokesTheGrant(): void
    {
        $client = $this->client();
        $created = $this->api($client, 'POST', '/tickets', $this->ticket() + self::guestProof($client, 'ada@example.com'));
        $other = $this->api($client, 'POST', '/tickets', $this->ticket() + self::guestProof($client, 'ada@example.com'));
        $em = static::entityManager();

        $this->find($created['reference'])->setGuestEmail('ADA@example.com ');
        $em->flush();
        $this->api($client, 'GET', '/tickets/'.$created['reference'], null, $created['guest_access_token']);
        self::assertSame(200, $this->code($client), 'the same address, differently written');

        $this->find($created['reference'])->setGuestEmail('eve@example.com');
        $em->flush();
        $this->api($client, 'GET', '/tickets/'.$created['reference'], null, $created['guest_access_token']);
        self::assertSame(404, $this->code($client));

        $ticket = $this->find($other['reference']);
        $ticket->setGuestAccess($ticket->getGuestVerifiedEmail(), $ticket->getGuestEmailVerifiedAt(), $ticket->getGuestAccessHash(), new \DateTimeImmutable('-1 minute'));
        $em->flush();
        $this->api($client, 'GET', '/tickets/'.$other['reference'], null, $other['guest_access_token']);
        self::assertSame(404, $this->code($client));
    }

    public function testLegacyTokensWorkUntilAGrantReplacesThem(): void
    {
        $client = $this->client();
        $ticket = $this->legacyTicket();

        $this->api($client, 'GET', '/tickets/'.$ticket->getReference(), null, (string) $ticket->getGuestToken());
        self::assertSame(200, $this->code($client));

        $this->api($client, 'POST', '/lookup', ['email' => 'ada@example.com', 'reference' => $ticket->getReference()] + self::guestProof($client, 'ada@example.com', 'lookup'));
        self::assertSame(200, $this->code($client));

        $this->api($client, 'GET', '/tickets/'.$ticket->getReference(), null, (string) $ticket->getGuestToken());
        self::assertSame(404, $this->code($client));
    }

    public function testLegacyTokensCanBeTurnedOff(): void
    {
        $client = $this->client(['guest_access' => ['accept_legacy_tokens' => false]]);
        $ticket = $this->legacyTicket();

        $this->api($client, 'GET', '/tickets/'.$ticket->getReference(), null, (string) $ticket->getGuestToken());

        self::assertSame(404, $this->code($client));
    }

    public function testLiveChatNeedsAChatProof(): void
    {
        $client = $this->client();
        $chat = ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hello'];

        $body = $this->api($client, 'POST', '/chat/start', $chat);
        self::assertSame(422, $this->code($client));
        self::assertArrayHasKey('verification_code', $body['errors']);

        $this->api($client, 'POST', '/chat/start', $chat + self::guestProof($client, 'ada@example.com'));
        self::assertSame(422, $this->code($client), 'a ticket proof cannot start a chat');

        $session = $this->api($client, 'POST', '/chat/start', $chat + self::guestProof($client, 'ada@example.com', 'chat'));
        self::assertSame(201, $this->code($client));
        $this->api($client, 'GET', '/chat/'.$session['id'].'/messages');
        self::assertSame(200, $this->code($client));
    }

    /** @param array<string, mixed> $escalated */
    private function client(array $escalated = []): KernelBrowser
    {
        $client = static::clientWithSchema(['escalated' => $escalated]);
        $settings = static::getContainer()->get(WidgetSettings::class);
        \assert($settings instanceof WidgetSettings);
        $settings->setEnabled(true);

        return $client;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function ticket(array $overrides = []): array
    {
        return $overrides + ['name' => 'Ada Guest', 'email' => 'ada@example.com', 'subject' => 'Printer on fire', 'description' => 'Smoke everywhere'];
    }

    private function legacyTicket(): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject('From before grants')
            ->setStatus(Ticket::STATUS_OPEN)
            ->setReference('ESC-L'.bin2hex(random_bytes(3)))
            ->setGuestName('Ada')
            ->setGuestEmail('ada@example.com')
            ->setGuestToken(bin2hex(random_bytes(16)));
        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function find(string $reference): Ticket
    {
        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $reference]);
        self::assertInstanceOf(Ticket::class, $ticket);

        return $ticket;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(KernelBrowser $client, string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $client->request($method, self::API.$path, server: array_filter([
            'REMOTE_ADDR' => $this->ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => null !== $token ? 'Bearer '.$token : null,
        ], static fn ($v): bool => null !== $v), content: null !== $body ? (string) json_encode($body) : null);

        $decoded = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    private function code(KernelBrowser $client): int
    {
        return $client->getResponse()->getStatusCode();
    }
}
