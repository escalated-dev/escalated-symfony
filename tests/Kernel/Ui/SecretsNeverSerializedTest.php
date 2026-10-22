<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\ApiToken;
use Escalated\Symfony\Entity\ImportJob;
use Escalated\Symfony\Entity\TwoFactor;
use Escalated\Symfony\Entity\Webhook;
use Escalated\Symfony\Entity\WebhookDelivery;
use Escalated\Symfony\Import\ImportCredentials;
use Escalated\Symfony\Service\ApiTokenService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Kernel\Fixtures\FakeImportAdapter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Stored secrets never reach the browser.
 *
 * Import source credentials, webhook signing secrets, two-factor seeds and
 * API token hashes are kept server-side so the bundle can use them. None of
 * them is shown back to an admin: every page and JSON response that carries
 * one of these records is checked here for the raw secret anywhere in the
 * body, through the real router.
 *
 * Not covered because this bundle has no such surface: an SSO settings page
 * (no SSO), and inbound mail secrets on the settings page (Mailgun, Postmark
 * and IMAP secrets come from the bundle configuration, not the page).
 */
final class SecretsNeverSerializedTest extends EscalatedWebTestCase
{
    private const WEBHOOK_SECRET = 'whsec-4c8e1a7b9d3f';
    private const WEBHOOK_URL = 'https://93.184.215.14/hooks/escalated';

    private KernelBrowser $client;
    private TestUser $admin;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($this->admin);
        $em->flush();
        $this->client->loginUser($this->admin);
    }

    // --- Import --------------------------------------------------------------

    public function testImportCredentialsStayOutOfTheIndexProgressAndStatusResponses(): void
    {
        $job = $this->importJob();
        $secrets = $this->importSecrets($job);

        $index = $this->page('/support/admin/import');
        $this->assertNoSecrets($secrets);
        $row = $index['props']['jobs'][0];
        self::assertSame($job->getId(), $row['id']);
        self::assertSame(
            ['id', 'platform', 'status', 'progress', 'field_mappings', 'error_log', 'started_at', 'completed_at', 'created_at'],
            array_keys($row),
        );

        $progress = $this->page('/support/admin/import/progress/'.$job->getId());
        $this->assertNoSecrets($secrets);
        self::assertSame(array_keys($row), array_keys($progress['props']['job']));

        $this->client->request('GET', '/support/admin/import/status/'.$job->getId());
        self::assertResponseIsSuccessful();
        $this->assertNoSecrets($secrets);
    }

    public function testThePagesSendOnlyTheLastFiftyImportErrors(): void
    {
        $job = $this->importJob();
        $errors = [];
        for ($i = 1; $i <= 60; ++$i) {
            $errors[] = ['entity_type' => 'tickets', 'source_id' => 'k'.$i, 'error' => 'boom', 'timestamp' => '2024-01-01T00:00:00+00:00'];
        }
        $job->setErrorLog($errors);
        static::entityManager()->flush();

        $page = $this->page('/support/admin/import/progress/'.$job->getId());

        self::assertCount(50, $page['props']['job']['error_log']);
        self::assertSame('k11', $page['props']['job']['error_log'][0]['source_id']);
        self::assertSame('k60', $page['props']['job']['error_log'][49]['source_id']);
    }

    public function testImportCredentialsStayOutOfTheConnectTestConnectionMappingAndReviewSteps(): void
    {
        $secrets = [FakeImportAdapter::TOKEN];

        $this->page('/support/admin/import/fakedesk/connect');
        $this->assertNoSecrets($secrets);

        $this->client->request('POST', '/support/admin/import/fakedesk/test-connection', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['credentials' => FakeImportAdapter::CREDENTIALS]));
        self::assertResponseIsSuccessful();
        self::assertSame(['success' => true], json_decode((string) $this->client->getResponse()->getContent(), true));
        $this->assertNoSecrets($secrets);

        $this->page('/support/admin/import/fakedesk/mapping');
        $this->assertNoSecrets($secrets);

        $this->client->request('POST', '/support/admin/import/fakedesk/review', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_INERTIA' => 'true',
        ], content: (string) json_encode(['entity_types' => ['tickets']]));
        self::assertResponseIsSuccessful();
        $this->assertNoSecrets($secrets);
    }

    public function testTheImportRunStillReadsTheStoredCredentialsServerSide(): void
    {
        $job = $this->importJob();
        $credentials = static::getContainer()->get(ImportCredentials::class);
        \assert($credentials instanceof ImportCredentials);

        static::entityManager()->clear();
        $stored = static::entityManager()->find(ImportJob::class, $job->getId());
        \assert($stored instanceof ImportJob);

        self::assertSame(FakeImportAdapter::CREDENTIALS, $credentials->open((string) $stored->getSealedCredentials()));
    }

    // --- Webhooks ------------------------------------------------------------

    public function testTheWebhookSecretStaysOutOfTheIndexEditAndDeliveryLogPages(): void
    {
        $webhook = $this->webhook();
        $delivery = (new WebhookDelivery())
            ->setWebhook($webhook)
            ->setEvent('ticket.created')
            ->setPayload(['ok' => true])
            ->setResponseCode(200)
            ->setAttempts(1);
        static::entityManager()->persist($delivery);
        static::entityManager()->flush();

        $index = $this->page('/support/admin/webhooks');
        $this->assertNoSecrets([self::WEBHOOK_SECRET]);
        self::assertTrue($index['props']['webhooks'][0]['has_secret']);
        self::assertArrayNotHasKey('secret', $index['props']['webhooks'][0]);

        $edit = $this->page('/support/admin/webhooks/'.$webhook->getId().'/edit');
        $this->assertNoSecrets([self::WEBHOOK_SECRET]);
        self::assertTrue($edit['props']['webhook']['has_secret']);
        self::assertSame(self::WEBHOOK_URL, $edit['props']['webhook']['url']);

        $this->page('/support/admin/webhooks/'.$webhook->getId().'/deliveries');
        $this->assertNoSecrets([self::WEBHOOK_SECRET]);
    }

    public function testSavingAWebhookWithTheSecretLeftBlankKeepsTheStoredSecret(): void
    {
        $webhook = $this->webhook();

        $this->updateWebhook($webhook, ['events' => ['ticket.created', 'ticket.updated'], 'secret' => '', 'clear_secret' => false]);

        $fresh = $this->freshWebhook($webhook);
        self::assertSame(self::WEBHOOK_SECRET, $fresh->getSecret());
        self::assertSame(['ticket.created', 'ticket.updated'], $fresh->getEvents());

        // A form that does not send the field at all keeps it too.
        $this->updateWebhook($webhook, []);
        self::assertSame(self::WEBHOOK_SECRET, $this->freshWebhook($webhook)->getSecret());
    }

    public function testANewWebhookSecretReplacesTheStoredOne(): void
    {
        $webhook = $this->webhook();

        $this->updateWebhook($webhook, ['secret' => 'whsec-new']);

        self::assertSame('whsec-new', $this->freshWebhook($webhook)->getSecret());
    }

    public function testClearSecretRemovesTheStoredWebhookSecret(): void
    {
        $webhook = $this->webhook();

        $this->updateWebhook($webhook, ['secret' => '', 'clear_secret' => true]);

        self::assertNull($this->freshWebhook($webhook)->getSecret());
        $page = $this->page('/support/admin/webhooks');
        self::assertFalse($page['props']['webhooks'][0]['has_secret']);
    }

    public function testCreatingAWebhookStillStoresTheSecretItIsGiven(): void
    {
        $this->client->jsonRequest('POST', '/support/admin/webhooks', [
            'url' => self::WEBHOOK_URL,
            'events' => ['ticket.created'],
            'secret' => self::WEBHOOK_SECRET,
            'active' => true,
        ]);
        self::assertResponseRedirects();

        static::entityManager()->clear();
        $webhook = static::entityManager()->getRepository(Webhook::class)->findOneBy([]);
        self::assertInstanceOf(Webhook::class, $webhook);
        self::assertSame(self::WEBHOOK_SECRET, $webhook->getSecret());
    }

    // --- API tokens ----------------------------------------------------------

    public function testTheApiTokenHashStaysOutOfTheTokenAdminPage(): void
    {
        $tokens = static::getContainer()->get(ApiTokenService::class);
        \assert($tokens instanceof ApiTokenService);
        ['token' => $token, 'plainTextToken' => $plain] = $tokens->createToken((string) $this->admin->getId(), 'CI');

        $this->page('/support/admin/api-tokens');

        $this->assertNoSecrets([$token->getToken(), $plain]);
    }

    // --- The serializer never sees a secret ---------------------------------

    public function testTheSerializerLeavesOutEveryStoredSecret(): void
    {
        $serializer = static::getContainer()->get('serializer');
        \assert($serializer instanceof NormalizerInterface);

        $webhook = $this->webhook();
        $job = $this->importJob();
        $twoFactor = (new TwoFactor())
            ->setUserId((string) $this->admin->getId())
            ->setSecret('TOTPSECRETBASE32XX')
            ->setRecoveryCodes(['rc-aaaa-bbbb']);
        $apiToken = (new ApiToken())->setUserId('1')->setName('CI')->setToken('hash-0f1e2d3c4b5a');

        $normalized = [
            'webhook' => $serializer->normalize($webhook),
            'import job' => $serializer->normalize($job),
            'two factor' => $serializer->normalize($twoFactor),
            'api token' => $serializer->normalize($apiToken),
        ];

        self::assertArrayNotHasKey('secret', $normalized['webhook']);
        self::assertArrayNotHasKey('sealedCredentials', $normalized['import job']);
        self::assertArrayNotHasKey('secret', $normalized['two factor']);
        self::assertArrayNotHasKey('recoveryCodes', $normalized['two factor']);
        self::assertArrayNotHasKey('token', $normalized['api token']);

        $json = (string) json_encode($normalized);
        foreach ([self::WEBHOOK_SECRET, (string) $job->getSealedCredentials(), 'TOTPSECRETBASE32XX', 'rc-aaaa-bbbb', 'hash-0f1e2d3c4b5a'] as $secret) {
            self::assertStringNotContainsString($secret, $json);
        }
        self::assertSame(self::WEBHOOK_URL, $normalized['webhook']['url']);
    }

    // --- Helpers -------------------------------------------------------------

    private function importJob(): ImportJob
    {
        $credentials = static::getContainer()->get(ImportCredentials::class);
        \assert($credentials instanceof ImportCredentials);
        $job = (new ImportJob('fakedesk', 'importing'))
            ->setUserId($this->admin->getId())
            ->setSealedCredentials($credentials->seal(FakeImportAdapter::CREDENTIALS))
            ->updateEntityProgress('tickets', processed: 4, total: 10, skipped: 0, failed: 0, cursor: null);
        $em = static::entityManager();
        $em->persist($job);
        $em->flush();

        return $job;
    }

    /** @return list<string> */
    private function importSecrets(ImportJob $job): array
    {
        return [FakeImportAdapter::TOKEN, (string) $job->getSealedCredentials()];
    }

    private function webhook(): Webhook
    {
        $webhook = (new Webhook())
            ->setUrl(self::WEBHOOK_URL)
            ->setEvents(['ticket.created'])
            ->setSecret(self::WEBHOOK_SECRET)
            ->setActive(true);
        static::entityManager()->persist($webhook);
        static::entityManager()->flush();

        return $webhook;
    }

    private function freshWebhook(Webhook $webhook): Webhook
    {
        static::entityManager()->clear();
        $fresh = static::entityManager()->find(Webhook::class, $webhook->getId());
        self::assertInstanceOf(Webhook::class, $fresh);

        return $fresh;
    }

    /** @param array<string, mixed> $changes */
    private function updateWebhook(Webhook $webhook, array $changes): void
    {
        $this->client->jsonRequest('PUT', '/support/admin/webhooks/'.$webhook->getId(), $changes + [
            'url' => self::WEBHOOK_URL,
            'events' => ['ticket.created'],
            'active' => true,
        ]);
        self::assertResponseRedirects('/support/admin/webhooks');
    }

    /** @return array<string, mixed> */
    private function page(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }

    /** @param list<string> $secrets */
    private function assertNoSecrets(array $secrets): void
    {
        $body = (string) $this->client->getResponse()->getContent();
        foreach ($secrets as $secret) {
            self::assertNotSame('', $secret);
            self::assertStringNotContainsString($secret, $body);
            // As a JSON string, where "/" comes out as "\/".
            self::assertStringNotContainsString(trim((string) json_encode($secret), '"'), $body);
        }
    }
}
