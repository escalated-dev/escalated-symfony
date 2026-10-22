<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\Attachment;
use Escalated\Symfony\Entity\AuditLog;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\SettingsService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * The admin settings sub-pages against escalated-laravel's controllers:
 * Admin/Settings/CsatSettings, DataRetention, DatabaseConnection (read-only
 * here), EmailSettings and PublicTickets.
 */
final class SettingsPagesTest extends EscalatedWebTestCase
{
    private const CONNECTION_KEYS = ['name', 'driver', 'database', 'is_current', 'is_host_default', 'reachable', 'migrated', 'ticket_count', 'error'];

    private KernelBrowser $client;

    /**
     * @param array<string, mixed> $escalated
     */
    private function boot(array $escalated = []): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class] + $escalated]);
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::getContainer()->get('doctrine')->getManagerForClass(TestUser::class);
        $em->persist($admin);
        $em->flush();
        $this->client->loginUser($admin);
    }

    public function testEveryRouteThePagesCallExists(): void
    {
        $this->boot();
        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $routes = $router->getRouteCollection();

        $expected = [
            'escalated.admin.settings.csat' => ['GET', '/support/admin/settings/csat'],
            'escalated.admin.settings.csat.update' => ['POST', '/support/admin/settings/csat'],
            'escalated.admin.settings.data-retention' => ['GET', '/support/admin/settings/data-retention'],
            'escalated.admin.settings.data-retention.update' => ['POST', '/support/admin/settings/data-retention'],
            'escalated.admin.settings.database' => ['GET', '/support/admin/settings/database'],
            'escalated.admin.settings.database.test' => ['POST', '/support/admin/settings/database/test'],
            'escalated.admin.settings.database.update' => ['POST', '/support/admin/settings/database'],
            'escalated.admin.settings.email' => ['GET', '/support/admin/settings/email'],
            'escalated.admin.settings.email.update' => ['POST', '/support/admin/settings/email'],
            'escalated.admin.settings.public-tickets' => ['GET', '/support/admin/settings/public-tickets'],
            'escalated.admin.settings.public-tickets.update' => ['POST', '/support/admin/settings/public-tickets'],
        ];
        foreach ($expected as $name => [$method, $path]) {
            $route = $routes->get($name);
            self::assertNotNull($route, $name);
            self::assertSame($path, $route->getPath(), $name);
            self::assertContains($method, $route->getMethods(), $name);
        }
        self::assertContains('PUT', $routes->get('escalated.admin.settings.public-tickets.update')->getMethods());
    }

    public function testCsatSettingsRenderDefaultsAndSave(): void
    {
        $this->boot();

        $page = $this->visit('/support/admin/settings/csat');
        self::assertSame(['component' => 'Escalated/Admin/Settings/CsatSettings', 'props' => ['settings' => [
            'csat_question_text' => 'How would you rate your support experience?',
            'csat_scale' => '1-5',
            'csat_delivery_trigger' => 'on_resolve',
            'csat_delay_hours' => 0,
        ]]], $this->only($page, ['settings']));

        $this->send('POST', '/support/admin/settings/csat', ['csat_question_text' => 'How did we do?', 'csat_scale' => '1-3', 'csat_delivery_trigger' => 'delayed', 'csat_delay_hours' => 24]);
        self::assertResponseRedirects('/support/admin/settings/csat', 303);
        self::assertContains('CSAT settings updated.', $this->flash('success'));

        $settings = $this->visit('/support/admin/settings/csat')['props']['settings'];
        self::assertSame(['csat_question_text' => 'How did we do?', 'csat_scale' => '1-3', 'csat_delivery_trigger' => 'delayed', 'csat_delay_hours' => 24], $settings);
        self::assertSame('24', $this->settings()->get('csat_delay_hours'));
    }

    public function testCsatSettingsValidation(): void
    {
        $this->boot();

        $this->send('POST', '/support/admin/settings/csat', ['csat_question_text' => '', 'csat_scale' => '1-10', 'csat_delivery_trigger' => 'later', 'csat_delay_hours' => 169]);
        self::assertSame(['csat_question_text', 'csat_scale', 'csat_delivery_trigger', 'csat_delay_hours'], array_keys($this->lastErrors()));

        $this->send('POST', '/support/admin/settings/csat', ['csat_question_text' => str_repeat('a', 501), 'csat_scale' => '1-5', 'csat_delivery_trigger' => 'manual', 'csat_delay_hours' => -1]);
        self::assertSame(['csat_question_text', 'csat_delay_hours'], array_keys($this->lastErrors()));
        self::assertNull($this->settings()->get('csat_scale'));

        $this->client->request('POST', '/support/admin/settings/csat', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{"csat_scale":"1-5"}');
        self::assertResponseStatusCodeSame(422);
    }

    public function testDataRetentionRendersSettingsAndPreview(): void
    {
        $this->boot();

        $page = $this->visit('/support/admin/settings/data-retention');
        self::assertSame('Escalated/Admin/Settings/DataRetention', $page['component']);
        self::assertSame([
            'retention_closed_tickets' => 'never',
            'retention_attachments' => 'never',
            'retention_audit_logs' => 'never',
            'retention_user_data_gdpr' => false,
        ], $page['props']['settings']);
        self::assertSame(['tickets' => 0, 'attachments' => 0, 'audit_logs' => 0], $page['props']['purgePreview']);

        $old = $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-400 days'));
        $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-10 days'));
        $this->ticket(Ticket::STATUS_OPEN, null);
        $gone = $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-400 days'));
        $gone->setDeletedAt(new \DateTimeImmutable('-1 day'));
        $em = static::entityManager();
        $attachment = (new Attachment())->setTicket($old)->setOriginalFilename('a.txt')->setStoredFilename('a.txt')->setPath('a.txt');
        $em->persist($attachment);
        $em->persist((new AuditLog())->setAction('created')->setEntityType('ticket'));
        $em->persist((new AuditLog())->setAction('updated')->setEntityType('ticket'));
        $em->flush();
        $em->createQuery('UPDATE '.Attachment::class.' a SET a.createdAt = :d')->setParameter('d', new \DateTimeImmutable('-400 days'))->execute();
        $em->createQuery('UPDATE '.AuditLog::class.' a SET a.createdAt = :d WHERE a.action = :a')->setParameter('d', new \DateTimeImmutable('-100 days'))->setParameter('a', 'created')->execute();

        $this->send('POST', '/support/admin/settings/data-retention', ['retention_closed_tickets' => '1_year', 'retention_attachments' => '1_year', 'retention_audit_logs' => '90_days', 'retention_user_data_gdpr' => true]);
        self::assertResponseRedirects('/support/admin/settings/data-retention', 303);
        self::assertContains('Data retention settings updated.', $this->flash('success'));
        self::assertSame('1', $this->settings()->get('retention_user_data_gdpr'));

        $page = $this->visit('/support/admin/settings/data-retention');
        self::assertSame([
            'retention_closed_tickets' => '1_year',
            'retention_attachments' => '1_year',
            'retention_audit_logs' => '90_days',
            'retention_user_data_gdpr' => true,
        ], $page['props']['settings']);
        self::assertSame(['tickets' => 1, 'attachments' => 1, 'audit_logs' => 1], $page['props']['purgePreview']);

        $this->send('POST', '/support/admin/settings/data-retention', ['retention_closed_tickets' => 'never', 'retention_attachments' => 'never', 'retention_audit_logs' => 'never', 'retention_user_data_gdpr' => '0']);
        self::assertSame('0', $this->settings()->get('retention_user_data_gdpr'));
        self::assertSame(['tickets' => 0, 'attachments' => 0, 'audit_logs' => 0], $this->visit('/support/admin/settings/data-retention')['props']['purgePreview']);
    }

    public function testDataRetentionValidation(): void
    {
        $this->boot();

        $this->send('POST', '/support/admin/settings/data-retention', ['retention_closed_tickets' => '90_days', 'retention_attachments' => 'forever', 'retention_audit_logs' => '1_year', 'retention_user_data_gdpr' => 'maybe']);
        self::assertSame(['retention_closed_tickets', 'retention_attachments', 'retention_audit_logs', 'retention_user_data_gdpr'], array_keys($this->lastErrors()));

        $this->send('POST', '/support/admin/settings/data-retention', ['retention_closed_tickets' => 'never', 'retention_attachments' => 'never', 'retention_audit_logs' => 'never']);
        self::assertSame(['retention_user_data_gdpr'], array_keys($this->lastErrors()));
        self::assertNull($this->settings()->get('retention_closed_tickets'));
    }

    public function testDatabaseConnectionIsAReadOnlyDescriptionOfEscalatedsConnection(): void
    {
        $this->boot();
        $this->ticket(Ticket::STATUS_OPEN, null);

        $page = $this->visit('/support/admin/settings/database');
        self::assertSame('Escalated/Admin/Settings/DatabaseConnection', $page['component']);
        self::assertSame(['connections', 'current', 'hostDefault', 'pinnedByConfig', 'tablePrefix'], array_keys($page['props']));
        self::assertSame('default', $page['props']['current']);
        self::assertSame('default', $page['props']['hostDefault']);
        self::assertTrue($page['props']['pinnedByConfig']);
        self::assertSame('escalated_', $page['props']['tablePrefix']);
        self::assertCount(1, $page['props']['connections']);
        $connection = $page['props']['connections'][0];
        self::assertSame(self::CONNECTION_KEYS, array_keys($connection));
        self::assertSame('default', $connection['name']);
        self::assertContains($connection['driver'], ['sqlite', 'pgsql', 'mysql']);
        self::assertIsString($connection['database']);
        self::assertStringNotContainsString('postgres:', $connection['database']);
        self::assertTrue($connection['is_current']);
        self::assertTrue($connection['is_host_default']);
        self::assertTrue($connection['reachable']);
        self::assertTrue($connection['migrated']);
        self::assertSame(1, $connection['ticket_count']);
        self::assertNull($connection['error']);

        // Saving is refused: the connection is configuration.
        $this->send('POST', '/support/admin/settings/database', ['connection' => 'default']);
        self::assertResponseRedirects('/support/admin/settings/database', 303);
        self::assertContains('The database connection is set in configuration and cannot be changed from here.', $this->flash('error'));

        $this->client->request('POST', '/support/admin/settings/database/test', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{"connection":"default"}');
        self::assertResponseIsSuccessful();
        $described = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(self::CONNECTION_KEYS, array_keys($described));
        self::assertTrue($described['migrated']);

        $this->client->request('POST', '/support/admin/settings/database/test', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{"connection":"nope"}');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('POST', '/support/admin/settings/database/test', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(422);
        $this->send('POST', '/support/admin/settings/database/test', ['connection' => '']);
        self::assertSame(['connection'], array_keys($this->lastErrors()));
    }

    public function testDatabaseConnectionListsEveryConnectionWhenEscalatedHasItsOwn(): void
    {
        $this->boot(['entity_manager' => 'support']);

        $page = $this->visit('/support/admin/settings/database');
        self::assertSame('support', $page['props']['current']);
        self::assertSame('default', $page['props']['hostDefault']);
        self::assertTrue($page['props']['pinnedByConfig']);
        $byName = array_column($page['props']['connections'], null, 'name');
        self::assertSame(['default', 'support'], array_keys($byName));
        self::assertFalse($byName['default']['is_current']);
        self::assertTrue($byName['default']['is_host_default']);
        self::assertFalse($byName['default']['migrated'], 'the host database has no Escalated tables');
        self::assertNull($byName['default']['ticket_count']);
        self::assertTrue($byName['support']['is_current']);
        self::assertFalse($byName['support']['is_host_default']);
        self::assertTrue($byName['support']['migrated']);
        self::assertSame(0, $byName['support']['ticket_count']);
    }

    public function testEmailSettingsRenderAndSave(): void
    {
        $this->boot();

        self::assertSame(['component' => 'Escalated/Admin/Settings/EmailSettings', 'props' => ['addresses' => [], 'defaultReplyAddress' => '']], $this->only($this->visit('/support/admin/settings/email'), ['addresses', 'defaultReplyAddress']));

        $this->send('POST', '/support/admin/settings/email', [
            'addresses' => [
                ['email' => 'support@example.com', 'display_name' => 'Support', 'department_id' => 3, 'dkim_status' => 'verified', 'extra' => 'dropped'],
                ['email' => 'billing@example.com', 'display_name' => '', 'department_id' => null],
            ],
            'default_reply_address' => 'support@example.com',
        ]);
        self::assertResponseRedirects('/support/admin/settings/email', 303);
        self::assertContains('Email settings updated.', $this->flash('success'));

        $page = $this->visit('/support/admin/settings/email');
        self::assertSame([
            ['email' => 'support@example.com', 'display_name' => 'Support', 'department_id' => 3, 'dkim_status' => 'verified'],
            ['email' => 'billing@example.com', 'display_name' => null, 'department_id' => null, 'dkim_status' => 'unknown'],
        ], $page['props']['addresses']);
        self::assertSame('support@example.com', $page['props']['defaultReplyAddress']);
        self::assertSame(
            [['email' => 'support@example.com', 'display_name' => 'Support', 'department_id' => 3, 'dkim_status' => 'verified'], ['email' => 'billing@example.com', 'display_name' => null, 'department_id' => null, 'dkim_status' => 'unknown']],
            json_decode((string) $this->settings()->get('email_addresses'), true),
        );

        // Leaving the reply address out keeps the stored one.
        $this->send('POST', '/support/admin/settings/email', ['addresses' => []]);
        self::assertResponseRedirects('/support/admin/settings/email', 303);
        self::assertSame([], $this->visit('/support/admin/settings/email')['props']['addresses']);
        self::assertSame('support@example.com', $this->settings()->get('default_reply_address'));
    }

    public function testEmailSettingsValidation(): void
    {
        $this->boot();

        $this->send('POST', '/support/admin/settings/email', [
            'addresses' => [
                ['email' => 'not-an-email', 'display_name' => str_repeat('a', 256), 'department_id' => 'x', 'dkim_status' => 'great'],
                ['display_name' => 'No address'],
            ],
            'default_reply_address' => 'nope',
        ]);
        self::assertSame(['addresses.0.email', 'addresses.0.display_name', 'addresses.0.department_id', 'addresses.0.dkim_status', 'addresses.1.email', 'default_reply_address'], array_keys($this->lastErrors()));

        $this->send('POST', '/support/admin/settings/email', ['default_reply_address' => 'a@example.com']);
        self::assertSame(['addresses'], array_keys($this->lastErrors()));
        self::assertNull($this->settings()->get('email_addresses'));
        self::assertNull($this->settings()->get('default_reply_address'));
    }

    public function testPublicTicketsRenderAndSaveEachMode(): void
    {
        $this->boot();

        self::assertSame(['component' => 'Escalated/Admin/Settings/PublicTickets', 'props' => ['settings' => [
            'guest_policy_mode' => 'unassigned',
            'guest_policy_user_id' => null,
            'guest_policy_signup_url_template' => '',
        ]]], $this->only($this->visit('/support/admin/settings/public-tickets'), ['settings']));

        $this->send('POST', '/support/admin/settings/public-tickets', ['guest_policy_mode' => 'guest_user', 'guest_policy_user_id' => '42', 'guest_policy_signup_url_template' => 'https://x.test/{email}']);
        self::assertResponseRedirects('/support/admin/settings/public-tickets', 303);
        self::assertContains('Guest policy updated.', $this->flash('success'));
        self::assertSame(['guest_policy_mode' => 'guest_user', 'guest_policy_user_id' => '42', 'guest_policy_signup_url_template' => ''], $this->visit('/support/admin/settings/public-tickets')['props']['settings']);

        $this->send('PUT', '/support/admin/settings/public-tickets', ['guest_policy_mode' => 'prompt_signup', 'guest_policy_user_id' => '42', 'guest_policy_signup_url_template' => 'https://x.test/signup?email={email}']);
        self::assertResponseRedirects('/support/admin/settings/public-tickets', 303);
        self::assertSame(['guest_policy_mode' => 'prompt_signup', 'guest_policy_user_id' => null, 'guest_policy_signup_url_template' => 'https://x.test/signup?email={email}'], $this->visit('/support/admin/settings/public-tickets')['props']['settings']);
    }

    public function testPublicTicketsValidation(): void
    {
        $this->boot();

        $this->send('POST', '/support/admin/settings/public-tickets', ['guest_policy_mode' => 'everyone', 'guest_policy_user_id' => '0', 'guest_policy_signup_url_template' => str_repeat('a', 501)]);
        self::assertSame(['guest_policy_mode', 'guest_policy_user_id', 'guest_policy_signup_url_template'], array_keys($this->lastErrors()));

        $this->send('POST', '/support/admin/settings/public-tickets', ['guest_policy_mode' => 'guest_user', 'guest_policy_user_id' => '']);
        self::assertSame(['guest_policy_user_id'], array_keys($this->lastErrors()));
        self::assertNull($this->settings()->get('guest_policy_mode'));
    }

    public function testNonAdminsAreForbidden(): void
    {
        $this->boot();
        $agent = new TestUser('agent@example.com');
        $em = static::getContainer()->get('doctrine')->getManagerForClass(TestUser::class);
        $em->persist($agent);
        $em->flush();
        $this->client->loginUser($agent);

        foreach ([
            ['GET', '/support/admin/settings/csat'], ['POST', '/support/admin/settings/csat'],
            ['GET', '/support/admin/settings/data-retention'], ['POST', '/support/admin/settings/data-retention'],
            ['GET', '/support/admin/settings/database'], ['POST', '/support/admin/settings/database'], ['POST', '/support/admin/settings/database/test'],
            ['GET', '/support/admin/settings/email'], ['POST', '/support/admin/settings/email'],
            ['GET', '/support/admin/settings/public-tickets'], ['POST', '/support/admin/settings/public-tickets'], ['PUT', '/support/admin/settings/public-tickets'],
        ] as [$method, $uri]) {
            $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: '{"guest_policy_mode":"unassigned","connection":"default"}');
            self::assertResponseStatusCodeSame(403, $method.' '.$uri);
        }
        self::assertNull($this->settings()->get('guest_policy_mode'));
    }

    private function ticket(string $status, ?\DateTimeImmutable $closedAt): Ticket
    {
        $ticket = (new Ticket())->setSubject('Ticket')->setReference('ESC-S'.bin2hex(random_bytes(4)))->setDescription('Body')->setStatus($status)->setClosedAt($closedAt);
        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function settings(): SettingsService
    {
        static::entityManager()->clear();
        $settings = static::getContainer()->get(SettingsService::class);
        \assert($settings instanceof SettingsService);

        return $settings;
    }

    /**
     * @param array<string, mixed> $page
     * @param list<string>         $keys
     *
     * @return array<string, mixed>
     */
    private function only(array $page, array $keys): array
    {
        self::assertSame($keys, array_keys($page['props']));

        return ['component' => $page['component'], 'props' => $page['props']];
    }

    /**
     * @return list<string>
     */
    private function flash(string $type): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($type);
    }

    /**
     * @return array<string, string>
     */
    private function lastErrors(): array
    {
        $errors = $this->flash('errors');

        return end($errors) ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
        ], content: (string) json_encode($body));
    }
}
