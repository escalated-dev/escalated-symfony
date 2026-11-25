<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Command;

use Escalated\Symfony\Scheduling\SchedulerHeartbeat;
use Escalated\Symfony\Service\SettingsService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * SLA checks, escalation rules, automations and snooze wake-ups run only when
 * the host schedules the commands, and nothing told an admin when it did not.
 * The SLA check records a heartbeat; the admin settings and reports pages
 * send `scheduler` ({healthy, last_run_at, message, commands}) and the shared
 * pages warn while it is unhealthy: missing, or older than 15 minutes.
 */
final class SchedulerHeartbeatTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::getContainer()->get('doctrine')->getManagerForClass(TestUser::class);
        $em->persist($admin);
        $em->flush();
        $this->client->loginUser($admin);
    }

    public function testTheAdminPagesWarnWhenTheSlaCheckHasNeverRun(): void
    {
        foreach (['/support/admin/settings', '/support/admin/reports', '/support/admin/reports/dashboard'] as $uri) {
            $scheduler = $this->visit($uri)['props']['scheduler'];

            self::assertFalse($scheduler['healthy'], $uri);
            self::assertNull($scheduler['last_run_at']);
            self::assertStringContainsString('not running', $scheduler['message']);
            self::assertStringContainsString('escalated:check-sla-breaches has never run', $scheduler['message']);
            self::assertContains(['command' => 'php bin/console escalated:check-sla-breaches', 'interval' => 'every minute'], $scheduler['commands']);
            foreach (['escalated:escalations:run', 'escalated:automations:run', 'escalated:wake-snoozed-tickets'] as $job) {
                self::assertContains('php bin/console '.$job, array_column($scheduler['commands'], 'command'));
            }
        }
    }

    public function testRunningTheSlaCheckRecordsTheHeartbeatAndClearsTheWarning(): void
    {
        $application = new Application(static::$kernel);
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);

        $before = new \DateTimeImmutable('-1 second');
        self::assertSame(0, $console->run(['command' => 'escalated:check-sla-breaches'], ['interactive' => false]), $console->getDisplay());

        $recorded = $this->settings()->get(SchedulerHeartbeat::SETTING);
        self::assertNotNull($recorded);
        self::assertGreaterThanOrEqual($before->getTimestamp(), (new \DateTimeImmutable($recorded))->getTimestamp());

        $scheduler = $this->visit('/support/admin/settings')['props']['scheduler'];
        self::assertTrue($scheduler['healthy']);
        self::assertSame($recorded, $scheduler['last_run_at']);
        self::assertStringNotContainsString('not running', $scheduler['message']);
    }

    public function testAHeartbeatOlderThanFifteenMinutesIsAWarning(): void
    {
        $heartbeat = static::getContainer()->get(SchedulerHeartbeat::class);
        \assert($heartbeat instanceof SchedulerHeartbeat);

        $heartbeat->record(new \DateTimeImmutable('-16 minutes'));
        $scheduler = $this->visit('/support/admin/settings')['props']['scheduler'];
        self::assertFalse($scheduler['healthy']);
        self::assertNotNull($scheduler['last_run_at']);
        self::assertStringContainsString('more than 15 minutes ago', $scheduler['message']);

        $heartbeat->record(new \DateTimeImmutable('-14 minutes'));
        self::assertTrue($this->visit('/support/admin/settings')['props']['scheduler']['healthy']);
    }

    private function settings(): SettingsService
    {
        $settings = static::getContainer()->get(SettingsService::class);
        \assert($settings instanceof SettingsService);

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful($uri);
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }
}
