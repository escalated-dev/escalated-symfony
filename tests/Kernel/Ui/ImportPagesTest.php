<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\ImportJob;
use Escalated\Symfony\Entity\ImportSourceMap;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Entity\WorkflowLog;
use Escalated\Symfony\Import\ImportContext;
use Escalated\Symfony\Import\ImportCredentials;
use Escalated\Symfony\Service\AutomationRunner;
use Escalated\Symfony\Service\EscalationService;
use Escalated\Symfony\Service\SlaService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Kernel\Fixtures\FakeImportAdapter;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The admin Import wizard the shared frontend drives
 * (src/pages/Admin/Import/{Index,Connect,Mapping,Review,Progress}.vue), on
 * escalated-laravel's routes and props (Admin\ImportController,
 * ImportService, ImportJob, ImportContext).
 *
 * The source is FakeImportAdapter, a tagged service as a host or plugin
 * bundle registers a Zendesk or Freshdesk adapter. The run happens on
 * kernel.terminate, which the test client fires after each request.
 */
final class ImportPagesTest extends EscalatedWebTestCase
{
    private const BASE = '/support/admin/import';
    private const FIELD_MAPPINGS = ['tickets' => ['subject' => 'title', 'state' => 'status']];
    private const SHARED = ['errors', 'auth', 'current_user', 'flash', 'escalated', 'plugin_ui', 'page'];

    private KernelBrowser $client;
    private TestUser $admin;
    private FakeImportAdapter $adapter;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($this->admin);
        // A contact the host already knows: imported as that user.
        $em->persist(new TestUser('ada@customer.test', []));
        $em->flush();
        $this->client->loginUser($this->admin);

        $adapter = static::getContainer()->get(FakeImportAdapter::class);
        \assert($adapter instanceof FakeImportAdapter);
        $this->adapter = $adapter;
    }

    public function testIndexListsTheAdaptersAndRecentJobs(): void
    {
        $job = $this->job('completed');

        $page = $this->screen(self::BASE, 'Escalated/Admin/Import/Index');

        self::assertSame([[
            'name' => 'fakedesk', 'display_name' => 'FakeDesk', 'credential_fields' => FakeImportAdapter::CREDENTIAL_FIELDS,
        ]], $page['props']['adapters']);
        $row = $page['props']['jobs'][0];
        self::assertSame($job->getId(), $row['id']);
        self::assertSame('fakedesk', $row['platform']);
        self::assertSame('completed', $row['status']);
        self::assertNotEmpty($row['created_at']);
        self::assertArrayNotHasKey('credentials', $row);
    }

    public function testTheWizardIsAdminOnly(): void
    {
        $agent = new TestUser('agent@example.com', ['ROLE_USER']);
        static::entityManager()->persist($agent);
        static::entityManager()->flush();
        $this->client->loginUser($agent);

        $this->client->request('GET', self::BASE, server: ['HTTP_X_INERTIA' => 'true']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testConnectRendersTheCredentialForm(): void
    {
        $page = $this->screen(self::BASE.'/fakedesk/connect', 'Escalated/Admin/Import/Connect');

        self::assertSame('fakedesk', $page['props']['platform']);
        self::assertSame('FakeDesk', $page['props']['display_name']);
        self::assertSame(FakeImportAdapter::CREDENTIAL_FIELDS, $page['props']['credential_fields']);
    }

    public function testConnectIs404ForAPlatformNoAdapterProvides(): void
    {
        $this->client->request('GET', self::BASE.'/nowhere/connect');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testTestConnectionAnswersSuccessFalseForRefusedCredentials(): void
    {
        $body = $this->connect(['api_token' => 'wrong'] + FakeImportAdapter::CREDENTIALS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['success' => false], $body);
    }

    public function testTestConnectionAnswers422WithTheErrorWhenTheSourceThrows(): void
    {
        $body = $this->connect(['subdomain' => 'down'] + FakeImportAdapter::CREDENTIALS);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['success' => false, 'error' => 'FakeDesk is unreachable'], $body);
    }

    public function testMappingSendsTheVisitorBackToConnectUntilAConnectionIsTested(): void
    {
        $this->client->request('GET', self::BASE.'/fakedesk/mapping');

        self::assertResponseRedirects(self::BASE.'/fakedesk/connect');
    }

    public function testMappingRendersDefaultsAndSourceFieldsReadWithTheStoredCredentials(): void
    {
        $this->connect();

        $page = $this->screen(self::BASE.'/fakedesk/mapping', 'Escalated/Admin/Import/Mapping', except: ['credentials']);

        self::assertSame(['contacts', 'tags', 'tickets', 'replies'], $page['props']['entity_types']);
        self::assertSame([
            'defaults' => ['subject' => 'title', 'state' => 'status'],
            'source_fields' => FakeImportAdapter::TICKET_SOURCE_FIELDS,
        ], $page['props']['mappings']['tickets']);
        self::assertSame(['defaults' => [], 'source_fields' => []], $page['props']['mappings']['contacts']);
        self::assertSame(FakeImportAdapter::CREDENTIALS, $this->adapter->sourceFieldCredentials);
        // The credentials stay on the server: Laravel never round-trips them.
        self::assertStringNotContainsString(FakeImportAdapter::TOKEN, (string) $this->client->getResponse()->getContent());
    }

    public function testReviewEchoesTheChoicesAndWritesNothing(): void
    {
        $page = $this->post(self::BASE.'/fakedesk/review', ['field_mappings' => self::FIELD_MAPPINGS, 'entity_types' => ['tickets', 'replies']]);

        self::assertSame('Escalated/Admin/Import/Review', $page['component']);
        self::assertSame('fakedesk', $page['props']['platform']);
        self::assertSame(self::FIELD_MAPPINGS, $page['props']['field_mappings']);
        self::assertSame(['tickets', 'replies'], $page['props']['entity_types']);
        self::assertSame([], static::entityManager()->getRepository(ImportJob::class)->findAll());
    }

    public function testStartRefusesWithoutATestedConnection(): void
    {
        $this->post(self::BASE.'/fakedesk/start', ['field_mappings' => self::FIELD_MAPPINGS]);

        self::assertResponseRedirects(self::BASE.'/fakedesk/connect');
        self::assertSame([], static::entityManager()->getRepository(ImportJob::class)->findAll());
    }

    public function testStartCreatesTheJobAndImportsTheTicketsEndToEndInImportMode(): void
    {
        $em = static::entityManager();
        $em->persist((new SlaPolicy())->setName('Default')->setIsDefault(true)->setIsActive(true)
            ->setFirstResponseHours(['high' => 1, 'urgent' => 1, 'medium' => 4])->setResolutionHours(['high' => 8, 'urgent' => 8, 'medium' => 24]));
        $em->persist((new Workflow())->setName('Every new ticket')->setTriggerEvent('ticket.created')->setConditions([])
            ->setActions([['type' => 'change_priority', 'value' => 'low']]));
        $em->flush();
        $importing = [];
        $this->adapter->onExtract = static function () use (&$importing): void {
            $importing[] = ImportContext::isImporting();
        };

        $job = $this->start();

        self::assertResponseRedirects(self::BASE.'/progress/'.$job->getId());
        self::assertSame('fakedesk', $job->getPlatform());
        self::assertEquals($this->admin->getId(), $job->getUserId());
        // MySQL keeps JSON object keys in its own order.
        self::assertEquals(self::FIELD_MAPPINGS, $job->getFieldMappings());

        self::assertSame('completed', $job->getStatus());
        self::assertNotNull($job->getCompletedAt());
        self::assertNull($job->getSealedCredentials());
        self::assertSame([], $job->getErrorLog());
        self::assertEquals(['total' => 3, 'processed' => 3, 'skipped' => 0, 'failed' => 0, 'cursor' => '2'], $job->getProgress()['tickets']);

        $tickets = $this->importedTickets($job);
        self::assertSame(['Refund please', 'Login broken', 'Feature idea'], array_map(static fn (Ticket $t) => $t->getSubject(), $tickets));
        self::assertSame(['open', 'resolved', 'open'], array_map(static fn (Ticket $t) => $t->getStatus(), $tickets));
        self::assertSame(['high', 'urgent', 'medium'], array_map(static fn (Ticket $t) => $t->getPriority(), $tickets));
        // Ada is a host user; Bob becomes an Escalated contact on a guest ticket.
        self::assertSame(TestUser::class, $tickets[0]->getRequesterClass());
        self::assertEquals($this->hostUserId('ada@customer.test'), $tickets[0]->getRequesterId());
        self::assertSame('bob@customer.test', $tickets[1]->getContact()?->getEmail());
        self::assertSame(['Billing'], array_map(static fn ($tag) => $tag->getName(), $tickets[0]->getTags()->toArray()));
        self::assertSame('2024-01-02T03:04:05+00:00', $tickets[0]->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
        self::assertSame(1, \count($em->getRepository(Reply::class)->findBy(['isInternalNote' => true])));
        self::assertSame(2, \count($em->getRepository(Reply::class)->findAll()));
        self::assertSame(1, \count($em->getRepository(Contact::class)->findAll()));

        // Import mode: in the run only; no workflow, no SLA clock.
        self::assertNotEmpty($importing);
        self::assertSame([true], array_values(array_unique($importing)));
        self::assertFalse(ImportContext::isImporting());
        self::assertSame([], $em->getRepository(WorkflowLog::class)->findAll());
        foreach ($tickets as $ticket) {
            self::assertNull($ticket->getSlaPolicy());
        }
    }

    public function testCredentialsAreNotStoredInClear(): void
    {
        $this->adapter->onExtract = static function (string $type, ?string $cursor): void {
            throw new \RuntimeException('source down');
        };

        $job = $this->start();

        $stored = static::entityManager()->getConnection()->fetchOne('SELECT credentials FROM escalated_import_jobs');
        self::assertIsString($stored);
        self::assertStringNotContainsString(FakeImportAdapter::TOKEN, $stored);
        self::assertSame('failed', $job->getStatus());
        // The session copy is gone once the job holds them.
        $this->client->request('GET', self::BASE.'/fakedesk/mapping');
        self::assertResponseRedirects(self::BASE.'/fakedesk/connect');
    }

    public function testProgressRendersTheJobTheScreenPolls(): void
    {
        $job = $this->runningJob();

        $page = $this->screen(self::BASE.'/progress/'.$job->getId(), 'Escalated/Admin/Import/Progress');

        $sent = $page['props']['job'];
        self::assertSame($job->getId(), $sent['id']);
        self::assertSame('importing', $sent['status']);
        self::assertEquals($job->getProgress(), $sent['progress']);
        self::assertSame($job->getErrorLog(), $sent['error_log']);
        self::assertEquals(self::FIELD_MAPPINGS, $sent['field_mappings']);
        self::assertArrayNotHasKey('credentials', $sent);
        self::assertStringNotContainsString(FakeImportAdapter::TOKEN, (string) $this->client->getResponse()->getContent());
    }

    public function testStatusAnswersThePoll(): void
    {
        $job = $this->runningJob();

        $this->client->request('GET', self::BASE.'/status/'.$job->getId());
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(['status', 'progress', 'error_log', 'started_at', 'completed_at'], array_keys($body));
        self::assertSame('importing', $body['status']);
        self::assertEquals($job->getProgress(), $body['progress']);
        self::assertSame($job->getErrorLog(), $body['error_log']);
    }

    public function testStatusIs404ForAnUnknownJob(): void
    {
        $this->client->request('GET', self::BASE.'/status/00000000-0000-0000-0000-000000000000');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testPausesMidRunAndResumesWithoutImportingAnythingTwice(): void
    {
        $em = static::entityManager();
        $this->adapter->onExtract = static function (string $type, ?string $cursor) use ($em): void {
            if ('tickets' === $type && '2' === $cursor) {
                $em->getConnection()->executeStatement("UPDATE escalated_import_jobs SET status = 'paused'");
            }
        };

        $job = $this->start();

        self::assertSame('paused', $job->getStatus());
        self::assertCount(3, $this->importedTickets($job));
        self::assertArrayNotHasKey('replies', $job->getProgress());
        self::assertNotNull($job->getSealedCredentials());

        $this->adapter->onExtract = null;
        $this->adapter->extractCalls = [];
        $this->post(self::BASE.'/resume/'.$job->getId());
        self::assertResponseRedirects(self::BASE.'/progress/'.$job->getId());

        $job = $this->reload($job);
        self::assertSame('completed', $job->getStatus());
        self::assertCount(3, $this->importedTickets($job));
        self::assertCount(2, $em->getRepository(Reply::class)->findAll());
        // The run picked tickets up from its saved cursor, not from the start.
        self::assertNotContains(['tickets', null], $this->adapter->extractCalls);
        self::assertContains(['tickets', '2'], $this->adapter->extractCalls);
    }

    public function testPauseStopsAnImportingJob(): void
    {
        $job = $this->runningJob();

        $this->post(self::BASE.'/pause/'.$job->getId());

        self::assertResponseRedirects(self::BASE.'/progress/'.$job->getId());
        self::assertSame('paused', $this->reload($job)->getStatus());
    }

    public function testResumeRunsAFailedJobAgainThroughMapping(): void
    {
        $job = $this->job('failed');

        $this->post(self::BASE.'/resume/'.$job->getId());

        $job = $this->reload($job);
        self::assertSame('completed', $job->getStatus());
        self::assertCount(3, $this->importedTickets($job));
    }

    public function testCancelStopsAJobForGoodAndDropsItsCredentials(): void
    {
        $job = $this->job('paused');

        $this->post(self::BASE.'/cancel/'.$job->getId());

        self::assertResponseRedirects(self::BASE.'/progress/'.$job->getId());
        $job = $this->reload($job);
        self::assertSame('failed', $job->getStatus());
        self::assertNotNull($job->getCompletedAt());
        self::assertNull($job->getSealedCredentials());
    }

    public function testImportModeHoldsBackSlaEscalationsAndAutomations(): void
    {
        $container = static::getContainer();
        $sla = $container->get(SlaService::class);
        $escalations = $container->get(EscalationService::class);
        $automations = $container->get(AutomationRunner::class);
        \assert($sla instanceof SlaService && $escalations instanceof EscalationService && $automations instanceof AutomationRunner);

        $results = ImportContext::suppress(static fn (): array => [$sla->checkBreaches(), $escalations->evaluateRules(), $automations->run()]);

        self::assertSame([0, 0, 0], $results);
        self::assertFalse(ImportContext::isImporting());
    }

    private function start(): ImportJob
    {
        self::assertSame(['success' => true], $this->connect());
        $this->post(self::BASE.'/fakedesk/start', ['field_mappings' => self::FIELD_MAPPINGS]);
        $job = static::entityManager()->getRepository(ImportJob::class)->findOneBy([], ['createdAt' => 'DESC']);
        self::assertInstanceOf(ImportJob::class, $job);

        return $this->reload($job);
    }

    private function reload(ImportJob $job): ImportJob
    {
        $em = static::entityManager();
        $em->clear();
        $fresh = $em->find(ImportJob::class, $job->getId());
        self::assertInstanceOf(ImportJob::class, $fresh);

        return $fresh;
    }

    /** @return list<Ticket> */
    private function importedTickets(ImportJob $job): array
    {
        $em = static::entityManager();
        $maps = $em->getRepository(ImportSourceMap::class)->findBy(['importJob' => $job->getId(), 'entityType' => 'tickets'], ['id' => 'ASC']);

        return array_map(static function (ImportSourceMap $map) use ($em): Ticket {
            $ticket = $em->find(Ticket::class, (int) $map->getEscalatedId());
            self::assertInstanceOf(Ticket::class, $ticket);

            return $ticket;
        }, $maps);
    }

    private function hostUserId(string $email): int|string|null
    {
        return static::entityManager()->getRepository(TestUser::class)->findOneBy(['email' => $email])?->getId();
    }

    private function job(string $status): ImportJob
    {
        $credentials = static::getContainer()->get(ImportCredentials::class);
        \assert($credentials instanceof ImportCredentials);
        $job = (new ImportJob('fakedesk', $status))->setSealedCredentials($credentials->seal(FakeImportAdapter::CREDENTIALS));
        $em = static::entityManager();
        $em->persist($job);
        $em->flush();

        return $job;
    }

    private function runningJob(): ImportJob
    {
        $job = $this->job('importing')
            ->setFieldMappings(self::FIELD_MAPPINGS)
            ->setErrorLog([['entity_type' => 'tickets', 'source_id' => 'k9', 'error' => 'boom', 'timestamp' => '2024-01-01T00:00:00+00:00']])
            ->updateEntityProgress('tickets', processed: 1, total: 3, skipped: 0, failed: 1, cursor: '2');
        static::entityManager()->flush();

        return $job;
    }

    /**
     * @param array<string, string> $credentials
     *
     * @return array<string, mixed>
     */
    private function connect(array $credentials = FakeImportAdapter::CREDENTIALS): array
    {
        $this->client->request('POST', self::BASE.'/fakedesk/test-connection', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['credentials' => $credentials]));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @param list<string> $except declared props Laravel does not send either
     *
     * @return array<string, mixed>
     */
    private function screen(string $uri, string $component, array $except = []): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);
        self::assertSame($component, $page['component']);

        $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/Fixtures/escalated-pages.json'), true);
        $declared = array_values(array_diff($manifest['props'][$component]['props'], $except));
        $sent = array_values(array_diff(array_keys($page['props']), self::SHARED));
        sort($declared);
        sort($sent);
        self::assertSame($declared, $sent, $component.' props');

        return $page;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|null
     */
    private function post(string $uri, array $body = []): ?array
    {
        $this->client->request('POST', $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
        ], content: (string) json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
