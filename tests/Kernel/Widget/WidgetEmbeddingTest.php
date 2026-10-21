<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Widget;

use Escalated\Symfony\Controller\Widget\WidgetScriptController;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\SettingsService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Kernel\ObtainsGuestProof;
use Escalated\Symfony\Widget\WidgetOrigins;
use Escalated\Symfony\Widget\WidgetSettings;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * The widget, ticket form and live chat running on another site. Only origins
 * on the allow list (WidgetSettings allowedOrigins plus the
 * `widget_allowed_origins` setting) get CORS grants; such requests always run
 * as a guest. Everything else is handled as before. Mirrors
 * escalated-laravel's tests/Feature/WidgetEmbeddingTest.php.
 */
final class WidgetEmbeddingTest extends EscalatedWebTestCase
{
    use ObtainsGuestProof;

    private const SHOP = 'https://shop.example.com';

    private const API = '/support/widget/api';

    private string $ip;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh address per test: the guest rate-limit counters live in a
        // filesystem pool shared by every test with the same configuration.
        $this->ip = sprintf('2001:db8::%x:%x', random_int(1, 0xFFFF), random_int(1, 0xFFFF));
    }

    /**
     * @param array<string, mixed> $escalated
     * @param list<string>         $allowedOrigins
     */
    private function client(array $escalated = [], array $allowedOrigins = [self::SHOP]): KernelBrowser
    {
        $client = static::clientWithSchema([] === $escalated ? [] : ['escalated' => $escalated]);

        $settings = static::getContainer()->get(WidgetSettings::class);
        \assert($settings instanceof WidgetSettings);
        $settings->setEnabled(true)->setAllowedOrigins($allowedOrigins);

        return $client;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     */
    private function post(KernelBrowser $client, string $path, array $body, ?string $origin = self::SHOP, array $server = []): Response
    {
        $body = self::withGuestProof($client, 'POST', self::API.$path, $body) ?? $body;
        $client->request('POST', self::API.$path, server: array_filter([
            'REMOTE_ADDR' => $this->ip,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
        ] + $server, static fn ($v): bool => null !== $v), content: (string) json_encode($body));

        return $client->getResponse();
    }

    private function get(KernelBrowser $client, string $path, ?string $origin = self::SHOP): Response
    {
        $client->request('GET', self::API.$path, server: array_filter([
            'REMOTE_ADDR' => $this->ip,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_ORIGIN' => $origin,
        ], static fn ($v): bool => null !== $v));

        return $client->getResponse();
    }

    private function preflight(KernelBrowser $client, string $path, string $origin): Response
    {
        $client->request('OPTIONS', self::API.$path, server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type',
        ]);

        return $client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketPayload(array $extra = []): array
    {
        return [
            'subject' => 'Where is my order?',
            'description' => 'It has not arrived yet.',
            'guest_name' => 'Jane Doe',
            'guest_email' => 'jane@example.com',
        ] + $extra;
    }

    private function ticket(string $reference): Ticket
    {
        $em = static::entityManager();
        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->findOneBy(['reference' => $reference]);
        self::assertInstanceOf(Ticket::class, $ticket);

        return $ticket;
    }

    private static function assertGranted(Response $response, string $origin = self::SHOP): void
    {
        self::assertSame($origin, $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('Retry-After', $response->headers->get('Access-Control-Expose-Headers'));
        self::assertContains('Origin', $response->getVary());
        self::assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    private static function assertNotGranted(Response $response): void
    {
        self::assertFalse($response->headers->has('Access-Control-Allow-Origin'), 'unexpected Access-Control-Allow-Origin: '.$response->headers->get('Access-Control-Allow-Origin'));
        self::assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
    }

    public function testAnswersAPreflightFromAnAllowedSite(): void
    {
        $client = $this->client();

        foreach (['/tickets', '/chat/start', '/tickets/ESC-1/replies'] as $path) {
            $response = $this->preflight($client, $path, self::SHOP);

            self::assertSame(204, $response->getStatusCode(), $path);
            self::assertSame(self::SHOP, $response->headers->get('Access-Control-Allow-Origin'));
            self::assertSame('GET, POST, OPTIONS', $response->headers->get('Access-Control-Allow-Methods'));
            self::assertSame('Accept, Authorization, Content-Type, X-Requested-With, X-Guest-Token', $response->headers->get('Access-Control-Allow-Headers'));
            self::assertSame('600', $response->headers->get('Access-Control-Max-Age'));
            self::assertSame('Origin', $response->headers->get('Vary'));
            self::assertFalse($response->headers->has('Access-Control-Allow-Credentials'));
        }
    }

    public function testGivesNoCorsGrantToASiteThatIsNotAllowed(): void
    {
        $client = $this->client();

        $preflight = $this->preflight($client, '/tickets', 'https://evil.example');
        self::assertSame(204, $preflight->getStatusCode());
        self::assertNotGranted($preflight);
        self::assertFalse($preflight->headers->has('Access-Control-Allow-Methods'));

        // Not refused outright any more: the browser withholds the answer.
        $config = $this->get($client, '/config', 'https://evil.example');
        self::assertSame(200, $config->getStatusCode());
        self::assertNotGranted($config);

        $created = $this->post($client, '/tickets', $this->ticketPayload(), 'https://evil.example');
        self::assertSame(201, $created->getStatusCode());
        self::assertNotGranted($created);
    }

    public function testAnEmptyAllowListAllowsNoOtherSite(): void
    {
        $client = $this->client(allowedOrigins: []);

        self::assertNotGranted($this->preflight($client, '/tickets', self::SHOP));
        self::assertNotGranted($this->get($client, '/config'));
        self::assertNotGranted($this->post($client, '/tickets', $this->ticketPayload()));
    }

    public function testLeavesSameOriginRequestsAlone(): void
    {
        $client = $this->client();

        $response = $this->get($client, '/config', 'http://localhost');

        self::assertSame(200, $response->getStatusCode());
        self::assertNotGranted($response);
    }

    public function testLetsAnAllowedSiteCreateATicket(): void
    {
        $client = $this->client();

        $response = $this->post($client, '/tickets', $this->ticketPayload());

        self::assertSame(201, $response->getStatusCode());
        self::assertGranted($response);
    }

    public function testReadsAllowedSitesFromTheAdminSettingIncludingSubdomainWildcards(): void
    {
        $client = $this->client(allowedOrigins: []);
        static::getContainer()->get(SettingsService::class)->set(WidgetOrigins::SETTING, "https://blog.example.com\nhttps://*.brand.test");

        self::assertGranted($this->get($client, '/config', 'https://blog.example.com'), 'https://blog.example.com');
        self::assertGranted($this->get($client, '/config', 'https://help.brand.test'), 'https://help.brand.test');
        self::assertNotGranted($this->get($client, '/config', 'https://brand.test'));
        self::assertNotGranted($this->get($client, '/config', 'http://help.brand.test'));
    }

    public function testTreatsAnEmbeddedVisitorAsAGuestEvenWhenSignedIn(): void
    {
        $client = $this->client(['visibility' => 'department']);
        $em = static::entityManager();
        $agent = new TestUser('agent@example.com');
        $em->persist($agent);
        $em->flush();
        $profile = new AgentProfile();
        $profile->setUserId($agent->getId());
        $em->persist($profile);
        $department = (new Department())->setName('Private')->setSlug('private');
        $em->persist($department);
        $ticket = (new Ticket())->setSubject('Secret')->setDepartment($department)->setGuestToken('guest-secret');
        $em->persist($ticket);
        $em->flush();
        $client->loginUser($agent);

        $path = self::API.'/tickets/'.$ticket->getReference();

        // Same site: the signed-in agent's department restriction applies.
        $client->request('GET', $path, server: ['HTTP_X_GUEST_TOKEN' => 'guest-secret']);
        self::assertResponseStatusCodeSame(404);

        // Embedded on another site: the session is not used; the guest token is.
        $client->request('GET', $path, server: ['HTTP_X_GUEST_TOKEN' => 'guest-secret', 'HTTP_ORIGIN' => self::SHOP]);
        self::assertResponseStatusCodeSame(200);
        self::assertGranted($client->getResponse());
        self::assertSame('Secret', json_decode((string) $client->getResponse()->getContent(), true)['data']['subject']);
    }

    public function testAddsCorsHeadersToErrorAnswersForAllowedSites(): void
    {
        // The 422 is counted too: the limit is checked before validation.
        $client = $this->client(['guest_rate_limit' => ['tickets_per_minute' => 2]]);

        $invalid = $this->post($client, '/tickets', ['subject' => 'No name']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertGranted($invalid);

        self::assertSame(201, $this->post($client, '/tickets', $this->ticketPayload())->getStatusCode());
        $limited = $this->post($client, '/tickets', $this->ticketPayload());
        self::assertSame(429, $limited->getStatusCode());
        self::assertTrue($limited->headers->has('Retry-After'));
        self::assertGranted($limited);

        $unauthorized = $this->get($client, '/tickets/ESC-1');
        self::assertSame(401, $unauthorized->getStatusCode());
        self::assertGranted($unauthorized);

        // An exception answer (no such route) still carries the grant.
        $missing = $this->get($client, '/no-such-endpoint');
        self::assertSame(404, $missing->getStatusCode());
        self::assertGranted($missing);
    }

    public function testTellsTheWidgetItAcceptsHostParameters(): void
    {
        $client = $this->client();

        $response = $this->get($client, '/config', null);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true)['data'];
        self::assertTrue($data['accepts_metadata']);
        self::assertTrue($data['enabled']);
        self::assertArrayHasKey('primary_color', $data);
    }

    public function testStoresHostParametersSentWithAWidgetTicket(): void
    {
        $client = $this->client();

        $response = $this->post($client, '/tickets', $this->ticketPayload(['metadata' => [
            'order_number' => 'A-1001',
            'plan' => 'pro',
            'items' => 3,
            'page_url' => 'https://shop.example.com/orders/A-1001',
            'embedded_on' => 'https://spoofed.example',
            'source' => 'spoofed',
            'nested' => ['not' => 'kept'],
            'bad key!' => 'dropped',
            'note' => str_repeat('a', 600),
        ]]));

        self::assertSame(201, $response->getStatusCode());
        $metadata = $this->ticket(json_decode((string) $response->getContent(), true)['data']['reference'])->getMetadata();

        self::assertSame('A-1001', $metadata['order_number']);
        self::assertSame('pro', $metadata['plan']);
        self::assertSame(3, $metadata['items']);
        self::assertSame('https://shop.example.com/orders/A-1001', $metadata['page_url']);
        self::assertSame(self::SHOP, $metadata['embedded_on']);
        self::assertSame('widget', $metadata['source']);
        self::assertSame(500, mb_strlen($metadata['note']));
        self::assertArrayNotHasKey('nested', $metadata);
        self::assertArrayNotHasKey('bad key!', $metadata);
    }

    public function testDropsAPageUrlThatIsNotAWebAddress(): void
    {
        $client = $this->client();

        $response = $this->post($client, '/tickets', $this->ticketPayload(['metadata' => ['page_url' => 'javascript:alert(1)', 'plan' => 'pro']]));

        $ticket = $this->ticket(json_decode((string) $response->getContent(), true)['data']['reference']);
        // MySQL's JSON type does not keep key order.
        self::assertEquals(['plan' => 'pro', 'embedded_on' => self::SHOP, 'source' => 'widget'], $ticket->getMetadata());
    }

    public function testLeavesWidgetTicketsWithoutHostParametersUnchanged(): void
    {
        $client = $this->client();

        $response = $this->post($client, '/tickets', $this->ticketPayload(), null);

        self::assertSame(201, $response->getStatusCode());
        $ticket = $this->ticket(json_decode((string) $response->getContent(), true)['data']['reference']);
        self::assertSame(['source' => 'widget'], $ticket->getMetadata());
    }

    public function testRejectsMetadataThatIsNotAnObject(): void
    {
        $client = $this->client();
        $tooMany = [];
        for ($i = 0; $i <= 50; ++$i) {
            $tooMany['k'.$i] = $i;
        }

        foreach (['plan=pro', ['a', 'b'], $tooMany] as $metadata) {
            $response = $this->post($client, '/tickets', $this->ticketPayload(['metadata' => $metadata]));
            self::assertSame(422, $response->getStatusCode());
            self::assertGranted($response);
        }

        self::assertSame(0, static::entityManager()->getRepository(Ticket::class)->count([]));
    }

    public function testAddsCorsHeadersToARateLimitedChatStartForAllowedSites(): void
    {
        $client = $this->client(['guest_rate_limit' => ['tickets_per_minute' => 1]]);
        $chat = ['name' => 'John Doe', 'email' => 'john@example.com', 'message' => 'Hello'];

        self::assertSame(201, $this->post($client, '/chat/start', $chat)->getStatusCode());

        $limited = $this->post($client, '/chat/start', $chat);
        self::assertSame(429, $limited->getStatusCode());
        self::assertTrue($limited->headers->has('Retry-After'));
        self::assertGranted($limited);
        self::assertSame('Retry-After', $limited->headers->get('Access-Control-Expose-Headers'));
    }

    public function testLetsAnAllowedSiteStartALiveChatAndKeepsItsHostParameters(): void
    {
        $client = $this->client();

        $response = $this->post($client, '/chat/start', [
            'guest_name' => 'John Doe',
            'guest_email' => 'john@example.com',
            'message' => 'Hello',
            'visitor_ip' => '203.0.113.9',
            'metadata' => ['page_url' => 'https://shop.example.com/cart', 'cart_total' => 129.5, 'deep' => ['x' => 1], 'embedded_on' => 'https://spoofed.example'],
        ], server: ['HTTP_USER_AGENT' => 'EmbedTest/1.0']);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        self::assertGranted($response);
        $ticket = $this->ticket(json_decode((string) $response->getContent(), true)['data']['ticket_reference']);

        $chat = $ticket->getChatMetadata();
        self::assertSame('https://shop.example.com/cart', $chat['page_url']);
        self::assertSame(129.5, $chat['cart_total']);
        self::assertSame(self::SHOP, $chat['embedded_on']);
        self::assertArrayHasKey('started_at', $chat);
        self::assertArrayNotHasKey('deep', $chat);

        $session = static::getContainer()->get(\Escalated\Symfony\Service\ChatSessionService::class)->findByTicket($ticket->getId());
        self::assertNotNull($session);
        self::assertSame($this->ip, $session->getVisitorIp());
        self::assertSame('EmbedTest/1.0', $session->getVisitorUserAgent());
        self::assertSame('https://shop.example.com/cart', $session->getVisitorPageUrl());
    }

    public function testServesTheStandaloneWidgetScriptForOtherSitesToLoad(): void
    {
        $client = $this->client(allowedOrigins: []);

        $client->request('GET', self::API.'/embed.js');
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/javascript; charset=utf-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('cross-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame(file_get_contents(WidgetScriptController::BUNDLE), $client->getInternalResponse()->getContent());

        $etag = (string) $response->headers->get('ETag');
        self::assertNotSame('', $etag);
        $client->request('GET', self::API.'/embed.js', server: ['HTTP_IF_NONE_MATCH' => $etag]);
        self::assertSame(304, $client->getResponse()->getStatusCode());
    }

    public function testLetsAdminsListEmbeddingSites(): void
    {
        $client = $this->client();
        $em = static::entityManager();
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em->persist($admin);
        $em->flush();
        $client->loginUser($admin);
        $settings = static::getContainer()->get(SettingsService::class);

        $client->jsonRequest('POST', '/support/admin/settings', ['widget_allowed_origins' => "https://shop.example.com\nhttps://*.brand.test"]);
        self::assertResponseRedirects();
        self::assertSame("https://shop.example.com\nhttps://*.brand.test", $settings->get(WidgetOrigins::SETTING));

        $client->jsonRequest('POST', '/support/admin/settings', ['widget_allowed_origins' => "https://shop.example.com/checkout\n*"]);
        self::assertResponseRedirects();
        self::assertSame("https://shop.example.com\nhttps://*.brand.test", $settings->get(WidgetOrigins::SETTING));

        $client->request('GET', '/support/admin/settings');
        self::assertResponseIsSuccessful();
        $props = json_decode((string) $client->getResponse()->getContent(), true)['props'];
        self::assertSame("https://shop.example.com\nhttps://*.brand.test", $props['settings']['widget_allowed_origins']);
        self::assertContains('widget_allowed_origins', $props['supported_settings']);
        self::assertSame('/support/admin/settings', $props['update_url']);
    }

    public function testOnlyAdminsCanChangeEmbeddingSites(): void
    {
        $client = $this->client();
        $em = static::entityManager();
        $user = new TestUser('someone@example.com');
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        $client->jsonRequest('POST', '/support/admin/settings', ['widget_allowed_origins' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertNull(static::getContainer()->get(SettingsService::class)->get(WidgetOrigins::SETTING));
    }
}
