<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Api;

use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Kernel\ObtainsGuestProof;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The mobile guest API (escalated-developer-context
 * domain-model/mobile-guest-api.md): the five guest routes under
 * `{route_prefix}/api/v1/mobile/guest`, on the same codes, grants and guest
 * rate limits as the widget and the browser guest portal.
 */
final class MobileGuestApiTest extends EscalatedWebTestCase
{
    use ObtainsGuestProof;

    private const API = '/support/api/v1/mobile/guest';
    private const WIDGET = '/support/widget/api';

    private string $ip;

    protected function setUp(): void
    {
        $this->ip = sprintf('2001:db8:3e::%x:%x', random_int(1, 0xFFFF), random_int(1, 0xFFFF));
    }

    public function testVerificationEmailsACodeWithPrivacyHeaders(): void
    {
        $client = $this->client();

        $body = $this->api($client, 'POST', '/verification', ['email' => ' Ada@Example.com ', 'purpose' => 'ticket']);

        self::assertSame(202, $this->code($client));
        self::assertIsString($body['verification_id']);
        self::assertSame(600, $body['expires_in']);
        self::assertSame('Check your email for a verification code.', $body['message']);
        self::assertMatchesRegularExpression('/^\d{8}$/', self::lastCodeSentTo('ada@example.com'));
        $this->assertPrivate($client);
    }

    public function testVerificationIsValidatedAndGatedByPurpose(): void
    {
        $client = $this->client();

        $body = $this->api($client, 'POST', '/verification', ['email' => 'nope', 'purpose' => 'ticket']);
        self::assertSame(422, $this->code($client));
        self::assertArrayHasKey('email', $body['errors']);
        $this->assertPrivate($client);

        $body = $this->api($client, 'POST', '/verification', ['email' => 'ada@example.com', 'purpose' => 'admin']);
        self::assertSame(422, $this->code($client));
        self::assertArrayHasKey('purpose', $body['errors']);

        // Live chat lives in the widget, which is off here.
        $this->api($client, 'POST', '/verification', ['email' => 'ada@example.com', 'purpose' => 'chat']);
        self::assertSame(403, $this->code($client));
    }

    public function testCreateWithoutProofIs422AndCreatesNothing(): void
    {
        $client = $this->client();

        $body = $this->api($client, 'POST', '/tickets', $this->ticket());

        self::assertSame(422, $this->code($client));
        self::assertArrayHasKey('verification_code', $body['errors']);
        self::assertSame(0, static::entityManager()->getRepository(Ticket::class)->count([]));
        self::assertSame(0, static::entityManager()->getRepository(Contact::class)->count([]));
        $this->assertPrivate($client);

        $proof = $this->proof($client, 'ada@example.com');
        $wrong = '00000000' === $proof['verification_code'] ? '11111111' : '00000000';
        $body = $this->api($client, 'POST', '/tickets', ['verification_code' => $wrong] + $proof + $this->ticket());
        self::assertSame(422, $this->code($client));
        self::assertArrayHasKey('verification_code', $body['errors']);
        self::assertSame(0, static::entityManager()->getRepository(Ticket::class)->count([]));
        self::assertSame(0, static::entityManager()->getRepository(Contact::class)->count([]));
    }

    public function testCreateValidatesFieldsBeforeTheProof(): void
    {
        $client = $this->client();

        $body = $this->api($client, 'POST', '/tickets', [
            'email' => 'not-an-email',
            'priority' => 'whenever',
            'department_id' => 999999,
            'verification_id' => 'not-a-uuid',
            'verification_code' => '12345678',
        ]);

        self::assertSame(422, $this->code($client));
        foreach (['name', 'email', 'subject', 'description', 'priority', 'department_id', 'verification_id'] as $field) {
            self::assertArrayHasKey($field, $body['errors'], $field);
        }
    }

    public function testVerifiedCreateReturnsTheMobileTicketAndTheCodeIsSpent(): void
    {
        $client = $this->client();
        $em = static::entityManager();
        $billing = (new Department())->setName('Billing')->setSlug('billing');
        $em->persist($billing);
        $em->flush();

        $proof = $this->proof($client, 'ada@example.com');
        $body = $this->api($client, 'POST', '/tickets', $this->ticket([
            'email' => ' ADA@Example.com ',
            'priority' => 'high',
            'department_id' => $billing->getId(),
            // Not for an anonymous caller to set.
            'status' => 'closed',
            'assigned_to' => 1,
            'metadata' => ['plan' => 'gold'],
            'external_reference' => 'EXT-1',
        ]) + $proof);

        self::assertSame(201, $this->code($client), (string) $client->getResponse()->getContent());
        $this->assertPrivate($client);
        self::assertSame('Ticket created.', $body['message']);
        $data = $body['data'];
        self::assertIsString($data['guest_access_token']);
        self::assertNotNull($data['guest_access_expires_at']);
        self::assertSame(['value' => 'open', 'label' => 'Open'], $data['status']);
        self::assertSame(['value' => 'high', 'label' => 'High'], $data['priority']);
        self::assertSame('web', $data['channel']);
        self::assertSame(['name' => 'Ada Guest', 'email' => 'ada@example.com'], $data['requester']);
        self::assertSame(['id' => $billing->getId(), 'name' => 'Billing'], $data['department']);
        self::assertNull($data['assignee']);
        self::assertSame([], $data['replies']);
        self::assertSame([], $data['tags']);
        self::assertSame([], $data['activities']);
        self::assertFalse($data['is_following']);
        self::assertSame(0, $data['followers_count']);
        self::assertSame([
            'first_response_due_at', 'first_response_at', 'first_response_breached', 'resolution_due_at', 'resolution_breached',
        ], array_keys($data['sla']));
        self::assertStringContainsString('"metadata":{}', (string) $client->getResponse()->getContent());
        self::assertSame([
            'id', 'reference', 'guest_access_token', 'guest_access_expires_at', 'subject', 'description', 'status', 'priority',
            'channel', 'metadata', 'requester', 'assignee', 'department', 'tags', 'replies', 'activities', 'sla',
            'is_following', 'followers_count', 'resolved_at', 'closed_at', 'created_at', 'updated_at',
        ], array_keys($data));

        $ticket = $this->find($data['reference']);
        self::assertSame('ada@example.com', $ticket->getGuestEmail());
        self::assertSame('ada@example.com', $ticket->getGuestVerifiedEmail());
        self::assertSame(Ticket::CHANNEL_WEB, $ticket->getChannel());
        self::assertSame(Ticket::STATUS_OPEN, $ticket->getStatus());
        self::assertNull($ticket->getAssignedTo());
        self::assertSame('ada@example.com', $ticket->getContact()?->getEmail());
        self::assertArrayNotHasKey('plan', $ticket->getMetadata() ?? []);

        $this->api($client, 'POST', '/tickets', $this->ticket() + $proof);
        self::assertSame(422, $this->code($client), 'a spent code');
    }

    public function testCreateIs403WhenGuestTicketsAreOff(): void
    {
        $client = $this->client();
        static::getContainer()->get(WidgetSettings::class)->setAllowGuestTickets(false);

        $body = $this->api($client, 'POST', '/tickets', $this->ticket());
        self::assertSame(403, $this->code($client));
        self::assertSame('Guest tickets are not enabled.', $body['message']);
        $this->assertPrivate($client);

        $this->api($client, 'POST', '/verification', ['email' => 'ada@example.com', 'purpose' => 'ticket']);
        self::assertSame(403, $this->code($client));
        $this->api($client, 'POST', '/lookup', ['email' => 'ada@example.com', 'reference' => 'ESC-1']);
        self::assertSame(403, $this->code($client));
    }

    public function testTheGrantWorksOnEverySurfaceBothWays(): void
    {
        $client = $this->client(['ui_enabled' => true]);
        static::getContainer()->get(WidgetSettings::class)->setEnabled(true);

        $created = $this->create($client);
        $grant = $created['guest_access_token'];

        $shown = $this->api($client, 'GET', '/tickets/'.$grant);
        self::assertSame(200, $this->code($client));
        self::assertSame($grant, $shown['data']['guest_access_token']);
        self::assertSame($created['reference'], $shown['data']['reference']);
        $this->assertPrivate($client);

        $this->request($client, 'GET', self::WIDGET.'/tickets/'.$created['reference'], null, $grant);
        self::assertSame(200, $this->code($client), 'the widget');
        $client->request('GET', '/support/guest/'.$grant, server: ['REMOTE_ADDR' => $this->ip, 'HTTP_X_INERTIA' => 'true']);
        self::assertSame(200, $this->code($client), 'the browser guest page');

        // And a widget grant opens the mobile routes.
        $widget = $this->request($client, 'POST', self::WIDGET.'/tickets', $this->ticket() + self::guestProof($client, 'ada@example.com'));
        self::assertSame(201, $this->code($client));
        $shown = $this->api($client, 'GET', '/tickets/'.$widget['guest_access_token']);
        self::assertSame(200, $this->code($client));
        self::assertSame($widget['reference'], $shown['data']['reference']);
    }

    public function testLookupIssuesAFreshGrantThatOpensTheTicket(): void
    {
        $client = $this->client();
        $created = $this->create($client);

        $found = $this->api($client, 'POST', '/lookup', [
            'email' => 'ADA@example.com',
            'reference' => $created['reference'],
        ] + $this->proof($client, 'ada@example.com', 'lookup'));

        self::assertSame(200, $this->code($client));
        $this->assertPrivate($client);
        self::assertCount(1, $found['data']);
        self::assertSame(['reference', 'subject', 'guest_access_token', 'expires_at'], array_keys($found['data'][0]));

        $this->api($client, 'GET', '/tickets/'.$found['data'][0]['guest_access_token']);
        self::assertSame(200, $this->code($client));
        $this->api($client, 'GET', '/tickets/'.$created['guest_access_token']);
        self::assertSame(404, $this->code($client), 'the earlier grant was rotated out');

        $this->api($client, 'POST', '/lookup', ['email' => 'ada@example.com', 'reference' => $created['reference']]);
        self::assertSame(422, $this->code($client), 'lookup without a proof');
    }

    public function testRepliesNeedTheTicketsEmailAndAnOpenTicket(): void
    {
        $client = $this->client();
        $created = $this->create($client);
        $grant = $created['guest_access_token'];

        $body = $this->api($client, 'POST', '/tickets/'.$grant.'/replies', ['email' => ' ADA@EXAMPLE.COM ', 'body' => 'Any news?']);
        self::assertSame(201, $this->code($client));
        self::assertSame('Reply sent.', $body['message']);
        $this->assertPrivate($client);

        $reply = static::entityManager()->getRepository(Reply::class)->findOneBy(['body' => 'Any news?']);
        self::assertInstanceOf(Reply::class, $reply);
        self::assertNull($reply->getAuthorId());
        self::assertFalse($reply->isInternalNote());

        $body = $this->api($client, 'POST', '/tickets/'.$grant.'/replies', ['email' => 'eve@example.com', 'body' => 'Hi']);
        self::assertSame(403, $this->code($client));
        self::assertSame('The provided email does not match this ticket.', $body['message']);
        $this->assertPrivate($client);

        $body = $this->api($client, 'POST', '/tickets/'.$grant.'/replies', ['email' => 'ada@example.com']);
        self::assertSame(422, $this->code($client));
        self::assertArrayHasKey('body', $body['errors']);

        $ticket = $this->find($created['reference']);
        $ticket->setStatus(Ticket::STATUS_CLOSED);
        static::entityManager()->flush();
        $body = $this->api($client, 'POST', '/tickets/'.$grant.'/replies', ['email' => 'ada@example.com', 'body' => 'Hello?']);
        self::assertSame(422, $this->code($client));
        self::assertSame('This ticket is closed.', $body['message']);
    }

    public function testShowListsPublicRepliesNewestFirstAndNeverInternalNotes(): void
    {
        $client = $this->client(['user_class' => TestUser::class]);
        $created = $this->create($client);
        $grant = $created['guest_access_token'];
        $tickets = static::getContainer()->get(TicketService::class);
        \assert($tickets instanceof TicketService);

        $this->api($client, 'POST', '/tickets/'.$grant.'/replies', ['email' => 'ada@example.com', 'body' => 'First from guest']);
        self::assertSame(201, $this->code($client));
        $ticket = $this->find($created['reference']);
        $tickets->addReply($ticket, 1, 'Agent answer', false, TestUser::class);
        $tickets->addReply($ticket, 1, 'Secret staff note', true, TestUser::class);

        $shown = $this->api($client, 'GET', '/tickets/'.$grant);

        self::assertSame(200, $this->code($client));
        self::assertStringNotContainsString('Secret staff note', (string) $client->getResponse()->getContent());
        $replies = $shown['data']['replies'];
        self::assertCount(2, $replies);
        self::assertSame(['Agent answer', 'First from guest'], array_column($replies, 'body'));
        self::assertSame(['id', 'body', 'is_internal_note', 'is_pinned', 'author', 'attachments', 'created_at'], array_keys($replies[0]));
        self::assertFalse($replies[0]['is_internal_note']);
        self::assertSame(0, $replies[0]['author']['id']);
        self::assertSame('', $replies[0]['author']['email']);
        self::assertSame(['id' => 0, 'name' => 'Ada Guest', 'email' => 'ada@example.com'], $replies[1]['author']);
    }

    public function testBadExpiredAndChatGrantsAre404(): void
    {
        $client = $this->client();
        static::getContainer()->get(WidgetSettings::class)->setEnabled(true);
        $created = $this->create($client);

        foreach ([
            substr($created['guest_access_token'], 0, -4),
            'not-a-grant',
            str_repeat('a', 5000),
        ] as $token) {
            $body = $this->api($client, 'GET', '/tickets/'.$token);
            self::assertSame(404, $this->code($client));
            self::assertSame('Ticket not found.', $body['message']);
            $this->assertPrivate($client);
            $this->api($client, 'POST', '/tickets/'.$token.'/replies', ['email' => 'ada@example.com', 'body' => 'x']);
            self::assertSame(404, $this->code($client));
        }

        // A chat token never opens a ticket route.
        $chat = $this->request($client, 'POST', self::WIDGET.'/chat/start', ['name' => 'Ada', 'email' => 'ada@example.com', 'message' => 'Hi']
            + self::guestProof($client, 'ada@example.com', 'chat'));
        self::assertSame(201, $this->code($client));
        $this->api($client, 'GET', '/tickets/'.$chat['id']);
        self::assertSame(404, $this->code($client));

        // Expired.
        $ticket = $this->find($created['reference']);
        $ticket->setGuestAccess($ticket->getGuestVerifiedEmail(), $ticket->getGuestEmailVerifiedAt(), $ticket->getGuestAccessHash(), new \DateTimeImmutable('-1 minute'));
        static::entityManager()->flush();
        $this->api($client, 'GET', '/tickets/'.$created['guest_access_token']);
        self::assertSame(404, $this->code($client));
    }

    public function testSubmissionsShareTheGuestBudgetWithTheWidget(): void
    {
        $client = $this->client(['guest_rate_limit' => ['tickets_per_minute' => 1]]);
        static::getContainer()->get(WidgetSettings::class)->setEnabled(true);

        $this->request($client, 'POST', self::WIDGET.'/tickets', $this->ticket());
        self::assertSame(422, $this->code($client), 'spends the widget submission budget');

        $body = $this->api($client, 'POST', '/tickets', $this->ticket());
        self::assertSame(429, $this->code($client));
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
        self::assertArrayHasKey('message', $body);
        $this->assertPrivate($client);

        $this->api($client, 'POST', '/verification', ['email' => 'ada@example.com', 'purpose' => 'ticket']);
        self::assertSame(429, $this->code($client), 'verification is a submission');
    }

    public function testRepliesBudget(): void
    {
        $client = $this->client(['guest_rate_limit' => ['replies_per_minute' => 1]]);

        $this->api($client, 'POST', '/tickets/not-a-grant/replies', ['email' => 'ada@example.com', 'body' => 'x']);
        self::assertSame(404, $this->code($client));
        $this->api($client, 'POST', '/lookup', ['email' => 'ada@example.com', 'reference' => 'ESC-1']);
        self::assertSame(429, $this->code($client));
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
    }

    public function testEveryGuestRouteCountsAgainstTheRequestsBudget(): void
    {
        $client = $this->client(['guest_rate_limit' => ['requests_per_minute' => 2]]);

        $this->api($client, 'GET', '/tickets/not-a-grant');
        $this->api($client, 'GET', '/tickets/not-a-grant');
        self::assertSame(404, $this->code($client));

        $this->api($client, 'GET', '/tickets/not-a-grant');
        self::assertSame(429, $this->code($client));
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
        $this->assertPrivate($client);
    }

    public function testRoutesAreServedOnAnApiOnlyHost(): void
    {
        $client = $this->client(['ui_enabled' => false]);

        $this->api($client, 'POST', '/verification', ['email' => 'ada@example.com', 'purpose' => 'ticket']);

        self::assertSame(202, $this->code($client));
    }

    /** @param array<string, mixed> $escalated */
    private function client(array $escalated = []): KernelBrowser
    {
        return static::clientWithSchema(['escalated' => $escalated]);
    }

    /**
     * @return array{verification_id: string, verification_code: string}
     */
    private function proof(KernelBrowser $client, string $email, string $purpose = 'ticket'): array
    {
        return self::guestProof($client, $email, $purpose, self::API);
    }

    /**
     * @return array<string, mixed> the created ticket's `data`
     */
    private function create(KernelBrowser $client): array
    {
        $body = $this->api($client, 'POST', '/tickets', $this->ticket() + $this->proof($client, 'ada@example.com'));
        self::assertSame(201, $this->code($client), (string) $client->getResponse()->getContent());

        return $body['data'];
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

    private function find(string $reference): Ticket
    {
        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $reference]);
        self::assertInstanceOf(Ticket::class, $ticket);

        return $ticket;
    }

    private function assertPrivate(KernelBrowser $client): void
    {
        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('no-store', $cache);
        self::assertStringContainsString('private', $cache);
        self::assertSame('no-referrer', $client->getResponse()->headers->get('Referrer-Policy'));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(KernelBrowser $client, string $method, string $path, ?array $body = null): array
    {
        return $this->request($client, $method, self::API.$path, $body);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(KernelBrowser $client, string $method, string $uri, ?array $body = null, ?string $token = null): array
    {
        $client->request($method, $uri, server: array_filter([
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
