<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Widget;

use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Entity\ChatRoutingRule;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\ChatSessionService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Kernel\ObtainsGuestProof;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * The requests the shared widget (`@escalated-dev/escalated`
 * src/widget/EscalatedWidget.vue, `widgetPath` = `{prefix}/widget/api`) sends,
 * byte for byte, and the response keys it reads. Mirrors escalated-laravel's
 * WidgetController and WidgetChatController.
 */
final class WidgetApiContractTest extends EscalatedWebTestCase
{
    use ObtainsGuestProof;

    private const API = '/support/widget/api';

    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ip = sprintf('2001:db8::%x:%x', random_int(1, 0xFFFF), random_int(1, 0xFFFF));
    }

    private function client(): KernelBrowser
    {
        $client = static::clientWithSchema();

        $settings = static::getContainer()->get(WidgetSettings::class);
        \assert($settings instanceof WidgetSettings);
        $settings->setEnabled(true);

        return $client;
    }

    /**
     * The widget's api(): JSON in, JSON out, optional bearer token.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function api(KernelBrowser $client, string $method, string $path, ?array $body = null, ?string $token = null): array
    {
        $body = self::withGuestProof($client, $method, self::API.$path, $body);
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

    private function department(string $name, bool $active = true): Department
    {
        $department = new Department();
        $department->setName($name);
        $department->setSlug(strtolower($name));
        $department->setIsActive($active);
        $em = static::entityManager();
        $em->persist($department);
        $em->flush();

        return $department;
    }

    public function testConfigExposesTheKeysTheWidgetReadsAtTheTopLevel(): void
    {
        $client = $this->client();
        $sales = $this->department('Sales');
        $this->department('Archive', false);

        $config = $this->api($client, 'GET', '/config');

        self::assertSame(200, $this->code($client));
        self::assertSame('#4F46E5', $config['color']);
        self::assertSame('bottom-right', $config['position']);
        self::assertSame('Hi! How can we help?', $config['greeting']);
        self::assertSame([['id' => $sales->getId(), 'name' => 'Sales']], $config['departments']);
        self::assertTrue($config['kb_enabled']);
        self::assertTrue($config['accepts_metadata']);
        // Guests prove their mailbox with an emailed code (POST /verification),
        // which also makes reference + email status lookup safe (POST /lookup).
        self::assertTrue($config['guest_verification_required']);
        self::assertTrue($config['status_lookup_enabled']);
        // The original envelope is still there for existing clients.
        self::assertSame('#4F46E5', $config['data']['primary_color']);
    }

    public function testConfigFollowsTheShowArticlesSwitch(): void
    {
        $client = $this->client();
        static::getContainer()->get(WidgetSettings::class)->setShowArticles(false);

        self::assertFalse($this->api($client, 'GET', '/config')['kb_enabled']);
    }

    public function testSearchesAndShowsPublishedArticles(): void
    {
        $client = $this->client();
        $em = static::entityManager();
        $published = (new Article())->setTitle('Reset your password')->setSlug('reset-password')
            ->setBody('<p>Open <b>settings</b> and choose reset password.</p>')->setStatus(Article::STATUS_PUBLISHED);
        $draft = (new Article())->setTitle('Password policy draft')->setSlug('password-draft')
            ->setBody('Draft')->setStatus(Article::STATUS_DRAFT);
        $em->persist($published);
        $em->persist($draft);
        $em->flush();

        $search = $this->api($client, 'GET', '/articles?q='.rawurlencode('password'));

        self::assertSame(200, $this->code($client));
        self::assertSame([[
            'title' => 'Reset your password',
            'slug' => 'reset-password',
            'excerpt' => 'Open settings and choose reset password.',
        ]], $search['articles']);

        $article = $this->api($client, 'GET', '/articles/reset-password');
        self::assertSame(200, $this->code($client));
        self::assertSame('Reset your password', $article['title']);
        self::assertSame('<p>Open <b>settings</b> and choose reset password.</p>', $article['body']);

        $this->api($client, 'GET', '/articles/password-draft');
        self::assertSame(404, $this->code($client));
    }

    public function testArticlesAreOffWhenTheWidgetHidesThem(): void
    {
        $client = $this->client();
        static::getContainer()->get(WidgetSettings::class)->setShowArticles(false);

        $this->api($client, 'GET', '/articles?q=password');
        self::assertSame(404, $this->code($client));
    }

    public function testCreatesATicketFromTheContactForm(): void
    {
        $client = $this->client();
        $sales = $this->department('Sales');

        $created = $this->api($client, 'POST', '/tickets', [
            'name' => 'Ada Guest',
            'email' => 'ada@example.com',
            'subject' => 'Order missing',
            'description' => 'It never arrived.',
            'department_id' => $sales->getId(),
            'metadata' => ['order' => 'A-1'],
        ]);

        self::assertSame(201, $this->code($client));
        self::assertIsString($created['reference']);
        self::assertIsString($created['guest_access_token']);
        self::assertSame($created['reference'], $created['data']['reference']);

        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $created['reference']]);
        self::assertNotNull($ticket);
        self::assertSame('Ada Guest', $ticket->getGuestName());
        self::assertSame('ada@example.com', $ticket->getGuestEmail());
        self::assertSame($sales->getId(), $ticket->getDepartment()?->getId());
        self::assertSame('A-1', $ticket->getMetadata()['order']);
    }

    public function testValidationErrorsCarryAMessageTheWidgetShows(): void
    {
        $client = $this->client();

        $body = $this->api($client, 'POST', '/tickets', ['name' => 'Ada', 'email' => 'ada@example.com']);

        self::assertSame(422, $this->code($client));
        self::assertNotEmpty($body['message']);
        self::assertSame($body['message'], $body['error']);
    }

    public function testRejectsAnUnknownDepartment(): void
    {
        $client = $this->client();

        $this->api($client, 'POST', '/tickets', [
            'name' => 'Ada', 'email' => 'ada@example.com', 'subject' => 'Hi', 'description' => 'x', 'department_id' => 9999,
        ]);

        self::assertSame(422, $this->code($client));
    }

    public function testShowsTicketStatusWithTheBearerToken(): void
    {
        $client = $this->client();
        $created = $this->api($client, 'POST', '/tickets', [
            'name' => 'Ada Guest', 'email' => 'ada@example.com', 'subject' => 'Order missing', 'description' => 'Where is it?',
        ]);

        $status = $this->api($client, 'GET', '/tickets/'.rawurlencode($created['reference']), null, $created['guest_access_token']);

        self::assertSame(200, $this->code($client));
        self::assertSame($created['reference'], $status['reference']);
        self::assertSame('Order missing', $status['subject']);
        self::assertSame('open', $status['status']);
        self::assertSame('Open', $status['status_label']);
        self::assertIsString($status['created_at']);
        self::assertSame([], $status['replies']);

        $this->api($client, 'GET', '/tickets/'.rawurlencode($created['reference']), null, 'not-the-token');
        self::assertSame(404, $this->code($client));
    }

    public function testChatAvailabilityIsTopLevel(): void
    {
        $client = $this->client();

        self::assertFalse($this->api($client, 'GET', '/chat/availability')['available']);

        $rule = (new ChatRoutingRule())->setName('Everyone')->setAgentIds([1]);
        static::entityManager()->persist($rule);
        static::entityManager()->flush();

        $availability = $this->api($client, 'GET', '/chat/availability');
        self::assertTrue($availability['available']);
        self::assertTrue($availability['data']['available']);
    }

    public function testRunsALiveChatTheWayTheWidgetDoes(): void
    {
        $client = $this->client();
        $sales = $this->department('Sales');

        $session = $this->api($client, 'POST', '/chat/start', [
            'name' => 'Ada Guest',
            'email' => 'ada@example.com',
            'department_id' => $sales->getId(),
            'message' => 'Hello there',
            'metadata' => ['plan' => 'pro'],
        ]);

        self::assertSame(201, $this->code($client));
        self::assertIsString($session['id']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $session['id']);
        self::assertSame(['Hello there'], array_map(static fn (array $m): string => $m['body'], $session['messages']));
        self::assertNull($session['agent']);
        $id = $session['id'];

        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $session['data']['ticket_reference']]);
        self::assertNotNull($ticket);
        self::assertSame('Ada Guest', $ticket->getGuestName());
        self::assertSame('ada@example.com', $ticket->getGuestEmail());
        self::assertSame($sales->getId(), $ticket->getDepartment()?->getId());

        $this->api($client, 'POST', '/chat/'.$id.'/messages', ['body' => 'Is anyone there?']);
        self::assertSame(200, $this->code($client));

        $this->api($client, 'POST', '/chat/'.$id.'/typing');
        self::assertSame(200, $this->code($client));

        $chatSession = static::getContainer()->get(ChatSessionService::class)->findByTicket((int) $ticket->getId());
        self::assertInstanceOf(ChatSession::class, $chatSession);
        static::getContainer()->get(ChatSessionService::class)->sendMessage($chatSession, 'Yes, how can I help?', 1, TestUser::class);

        $poll = $this->api($client, 'GET', '/chat/'.$id.'/messages');
        self::assertSame(200, $this->code($client));
        self::assertFalse($poll['ended']);
        $bodies = array_map(static fn (array $m): string => $m['body'], $poll['messages']);
        self::assertSame(['Hello there', 'Is anyone there?', 'Yes, how can I help?'], $bodies);
        foreach ($poll['messages'] as $message) {
            self::assertArrayHasKey('id', $message);
            self::assertArrayHasKey('created_at', $message);
            self::assertIsString($message['author']['name']);
        }
        self::assertFalse($poll['messages'][1]['is_agent']);
        self::assertSame('Ada Guest', $poll['messages'][1]['author']['name']);
        self::assertTrue($poll['messages'][2]['is_agent']);

        $this->api($client, 'POST', '/chat/'.$id.'/end');
        self::assertSame(200, $this->code($client));
        self::assertTrue($this->api($client, 'GET', '/chat/'.$id.'/messages')['ended']);

        $this->api($client, 'POST', '/chat/'.$id.'/rate', ['rating' => 5, 'comment' => 'Great']);
        self::assertSame(200, $this->code($client));
        static::entityManager()->clear();
        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $session['reference']]);
        self::assertNotNull($ticket);
        self::assertSame(5, $ticket->getChatMetadata()['rating']);
        self::assertSame('Great', $ticket->getChatMetadata()['rating_comment']);
    }

    public function testChatStartAnswersWithTheSameFieldsAsLaravel(): void
    {
        $client = $this->client();
        $session = $this->api($client, 'POST', '/chat/start', ['name' => 'Ada', 'email' => 'ada@example.com']);

        self::assertSame(201, $this->code($client));
        // `id` and `session_id` are both the chat token, as in escalated-laravel.
        self::assertSame($session['id'], $session['session_id']);
        self::assertGreaterThan(time(), strtotime((string) $session['expires_at']));
        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $session['ticket_reference']]);
        self::assertNotNull($ticket);
        self::assertSame('waiting', $session['status']);
        self::assertArrayHasKey('agent_name', $session);
        self::assertNull($session['agent_name']);
        // The numeric session id the token replaced is not exposed anywhere.
        self::assertSame($session['id'], $session['data']['session_id']);
    }

    public function testChatIdIsTheSecret(): void
    {
        $client = $this->client();
        $session = $this->api($client, 'POST', '/chat/start', ['name' => 'Ada', 'email' => 'ada@example.com']);

        // The numeric session id and the ticket reference are not enough.
        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $session['ticket_reference']]);
        self::assertNotNull($ticket);
        $chat = static::getContainer()->get(ChatSessionService::class)->findByTicket((int) $ticket->getId());
        self::assertInstanceOf(ChatSession::class, $chat);
        $this->api($client, 'GET', '/chat/'.$chat->getId().'/messages');
        self::assertSame(404, $this->code($client));
        $this->api($client, 'POST', '/chat/'.$session['data']['ticket_reference'].'/messages', ['body' => 'hi']);
        self::assertSame(404, $this->code($client));
        $this->api($client, 'POST', '/chat/00000000-0000-4000-8000-000000000000/messages', ['body' => 'hi']);
        self::assertSame(404, $this->code($client));
    }

    public function testTheChatTokenExpiresWithTheGuestAccessLifetimeAndOnlyItsHashIsStored(): void
    {
        $client = $this->client();
        $before = time();
        $session = $this->api($client, 'POST', '/chat/start', ['name' => 'Ada', 'email' => 'ada@example.com']);
        self::assertSame(201, $this->code($client));

        $ttl = static::getContainer()->get(\Escalated\Symfony\Security\GuestAccess::class)->ttlMinutes() * 60;
        $expires = (new \DateTimeImmutable($session['expires_at']))->getTimestamp();
        self::assertGreaterThanOrEqual($before + $ttl, $expires);
        self::assertLessThanOrEqual(time() + $ttl, $expires);
        self::assertSame($session['expires_at'], $session['data']['expires_at']);

        static::entityManager()->clear();
        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $session['reference']]);
        self::assertNotNull($ticket);
        self::assertNull($ticket->getGuestToken());
        $chat = static::getContainer()->get(ChatSessionService::class)->findByTicket((int) $ticket->getId());
        self::assertInstanceOf(ChatSession::class, $chat);
        self::assertSame(hash('sha256', $session['id']), $chat->getVisitorTokenHash());
        self::assertSame($expires, $chat->getVisitorTokenExpiresAt()?->getTimestamp());
    }

    public function testEveryChatRouteAnswers404OnceTheTokenHasExpired(): void
    {
        $client = $this->client();
        $session = $this->api($client, 'POST', '/chat/start', ['name' => 'Ada', 'email' => 'ada@example.com']);
        $id = $session['id'];
        $this->expireChat($session['reference'], new \DateTimeImmutable('-1 minute'));

        $this->api($client, 'GET', '/chat/'.$id.'/messages');
        self::assertSame(404, $this->code($client));
        foreach (['messages', 'typing', 'end', 'rate'] as $action) {
            $this->api($client, 'POST', '/chat/'.$id.'/'.$action, ['body' => 'x', 'rating' => 5]);
            self::assertSame(404, $this->code($client), $action);
        }
        $client->request('POST', self::API.'/chat/sessions/'.$session['reference'].'/messages', server: [
            'REMOTE_ADDR' => $this->ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GUEST_TOKEN' => $id,
        ], content: (string) json_encode(['body' => 'Still here']));
        self::assertSame(404, $this->code($client));
    }

    public function testAChatStartedBeforeTokensExpiredKeepsWorkingWithAFullLifetime(): void
    {
        $client = $this->client();
        $session = $this->api($client, 'POST', '/chat/start', ['name' => 'Ada', 'email' => 'ada@example.com']);
        $id = $session['id'];
        // The shape such a chat has after the migration: the plain token on
        // the ticket, nothing on the session.
        $em = static::entityManager();
        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->findOneBy(['reference' => $session['reference']]);
        self::assertNotNull($ticket);
        $ticket->setGuestToken($id);
        $chat = static::getContainer()->get(ChatSessionService::class)->findByTicket((int) $ticket->getId());
        self::assertInstanceOf(ChatSession::class, $chat);
        $chat->setVisitorTokenHash(null)->setVisitorTokenExpiresAt(null);
        $em->flush();
        $before = time();

        $this->api($client, 'GET', '/chat/'.$id.'/messages');
        self::assertSame(200, $this->code($client));
        $this->api($client, 'POST', '/chat/'.$id.'/messages', ['body' => 'Still here']);
        self::assertSame(200, $this->code($client));

        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->findOneBy(['reference' => $session['reference']]);
        self::assertNotNull($ticket);
        self::assertNull($ticket->getGuestToken());
        $chat = static::getContainer()->get(ChatSessionService::class)->findByTicket((int) $ticket->getId());
        self::assertSame(hash('sha256', $id), $chat?->getVisitorTokenHash());
        $ttl = static::getContainer()->get(\Escalated\Symfony\Security\GuestAccess::class)->ttlMinutes() * 60;
        self::assertGreaterThanOrEqual($before + $ttl, (int) $chat?->getVisitorTokenExpiresAt()?->getTimestamp());
    }

    public function testAnEndedChatIsClosedToTypingAndASecondEndButStaysReadable(): void
    {
        $client = $this->client();
        $id = $this->api($client, 'POST', '/chat/start', ['name' => 'Ada', 'email' => 'ada@example.com'])['id'];

        $this->api($client, 'POST', '/chat/'.$id.'/end');
        self::assertSame(200, $this->code($client));
        $this->api($client, 'POST', '/chat/'.$id.'/typing');
        self::assertSame(422, $this->code($client));
        $this->api($client, 'POST', '/chat/'.$id.'/end');
        self::assertSame(422, $this->code($client));
        self::assertTrue($this->api($client, 'GET', '/chat/'.$id.'/messages')['ended']);
        $this->api($client, 'POST', '/chat/'.$id.'/rate', ['rating' => 4]);
        self::assertSame(200, $this->code($client));
    }

    private function expireChat(string $reference, \DateTimeImmutable $at): void
    {
        $em = static::entityManager();
        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->findOneBy(['reference' => $reference]);
        self::assertNotNull($ticket);
        $chat = static::getContainer()->get(ChatSessionService::class)->findByTicket((int) $ticket->getId());
        self::assertInstanceOf(ChatSession::class, $chat);
        $chat->setVisitorTokenExpiresAt($at);
        $em->flush();
    }

    public function testRatingNeedsAnEndedChatAndAValidScore(): void
    {
        $client = $this->client();
        $id = $this->api($client, 'POST', '/chat/start', ['name' => 'Ada', 'email' => 'ada@example.com'])['id'];

        $this->api($client, 'POST', '/chat/'.$id.'/rate', ['rating' => 5]);
        self::assertSame(422, $this->code($client));

        $this->api($client, 'POST', '/chat/'.$id.'/end');
        $this->api($client, 'POST', '/chat/'.$id.'/rate', ['rating' => 9]);
        self::assertSame(422, $this->code($client));

        $this->api($client, 'POST', '/chat/'.$id.'/messages', ['body' => 'late']);
        self::assertSame(422, $this->code($client));
    }

    public function testTheOriginalFieldNamesAndRoutesStillWork(): void
    {
        $client = $this->client();

        $client->request('POST', self::API.'/chat/start', server: [
            'REMOTE_ADDR' => $this->ip,
            'CONTENT_TYPE' => 'application/json',
        ], content: (string) json_encode(['guest_name' => 'Old', 'guest_email' => 'old@example.com'] + self::guestProof($client, 'old@example.com', 'chat')));
        self::assertSame(201, $this->code($client));
        $data = json_decode((string) $client->getResponse()->getContent(), true)['data'];

        $client->request('POST', self::API.'/chat/sessions/'.$data['ticket_reference'].'/messages', server: [
            'REMOTE_ADDR' => $this->ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GUEST_TOKEN' => $data['guest_token'],
        ], content: (string) json_encode(['body' => 'Still here']));
        self::assertSame(201, $this->code($client));
    }

    /**
     * A guest who answers the notification email wrote that reply: the widget
     * must not show it as Support's. Inbound guest replies are tagged with an
     * `inbound_email` author class, which the old `authorClass !== null` check
     * read as an agent.
     */
    public function testRepliesTheRequesterSentByEmailAreNotAgentReplies(): void
    {
        $client = $this->client();
        $created = $this->api($client, 'POST', '/tickets', [
            'name' => 'Ada Guest', 'email' => 'ada@example.com', 'subject' => 'Order missing', 'description' => 'Where is it?',
        ]);
        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $created['reference']]);
        self::assertNotNull($ticket);
        $tickets = static::getContainer()->get(TicketService::class);
        \assert($tickets instanceof TicketService);
        $tickets->addReply($ticket, 1, 'We are looking into it.', false, TestUser::class);
        $tickets->addInboundEmailReply($ticket, 'Thanks, any news?');

        $status = $this->api($client, 'GET', '/tickets/'.rawurlencode($created['reference']), null, $created['guest_access_token']);

        self::assertSame(200, $this->code($client));
        $byBody = array_column($status['replies'], null, 'body');
        self::assertTrue($byBody['We are looking into it.']['is_agent']);
        self::assertSame('Support', $byBody['We are looking into it.']['author']);
        self::assertFalse($byBody['Thanks, any news?']['is_agent']);
        self::assertSame('Ada Guest', $byBody['Thanks, any news?']['author']);
    }

    public function testRequesterUserRepliesAreNotAgentRepliesInAChat(): void
    {
        $client = $this->client();
        $session = $this->api($client, 'POST', '/chat/start', ['name' => 'Ada Guest', 'email' => 'ada@example.com', 'message' => 'Hello']);
        self::assertSame(201, $this->code($client));
        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['reference' => $session['data']['ticket_reference']]);
        self::assertNotNull($ticket);
        $ticket->setRequesterId(42)->setRequesterClass(TestUser::class);
        static::entityManager()->flush();
        $tickets = static::getContainer()->get(TicketService::class);
        \assert($tickets instanceof TicketService);
        $tickets->addInboundEmailReply($ticket, 'Guest by email');
        $tickets->addInboundEmailReply($ticket, 'Requester by email', 42, TestUser::class);
        $tickets->addReply($ticket, 1, 'Agent here', false, TestUser::class);

        $poll = $this->api($client, 'GET', '/chat/'.$session['id'].'/messages');

        self::assertSame(200, $this->code($client));
        $isAgent = array_column($poll['messages'], 'is_agent', 'body');
        self::assertFalse($isAgent['Guest by email']);
        self::assertFalse($isAgent['Requester by email']);
        self::assertTrue($isAgent['Agent here']);
    }
}
