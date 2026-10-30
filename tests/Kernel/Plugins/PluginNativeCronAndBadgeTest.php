<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Plugins;

use Escalated\Symfony\Entity\PluginStoreRecord;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\AuditPlugin;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\PluginRecorder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Native plugins get the same cron schedules and widget badges as SDK
 * plugins (CronPluginInterface, WidgetBadgePluginInterface), without the
 * plugin runtime.
 */
final class PluginNativeCronAndBadgeTest extends PluginTestCase
{
    protected function setUp(): void
    {
        $this->boot();
    }

    public function testDueCronSchedulesOfActiveNativePluginsRunAsNoUser(): void
    {
        $nine = new \DateTimeImmutable('2026-10-29T09:00:20Z');
        self::assertSame(0, $this->registry()->runDueCron($nine));
        self::assertSame([], $this->cronCalls(), 'an inactive plugin runs nothing');

        $this->loginAdmin();
        $this->activate(AuditPlugin::SLUG);
        $tester = new CommandTester((new Application(static::$kernel))->find('escalated:plugins:cron'));
        self::assertSame(0, $tester->execute(['--at' => '2026-10-29T09:00:20Z']));

        self::assertSame(['* * * * *', '0 9 * * *'], array_column($this->cronCalls(), 'hook'), 'due ones only; the unreadable one is skipped');
        self::assertSame([null, null], array_column(array_column($this->cronCalls(), 'event'), 'user'), 'no acting user');
        self::assertCount(2, static::entityManager()->getRepository(PluginStoreRecord::class)->findBy(['plugin' => AuditPlugin::NAME, 'collection' => 'cron']));

        PluginRecorder::$calls = [];
        self::assertSame(1, $this->registry()->runDueCron($nine->modify('+1 minute')));
        self::assertSame(['* * * * *'], array_column($this->cronCalls(), 'hook'));
    }

    public function testAFailingScheduleNeverStopsTheOthers(): void
    {
        $this->activate(AuditPlugin::SLUG);
        PluginRecorder::$throwOn = [AuditPlugin::NAME.':* * * * *'];

        self::assertSame(2, $this->registry()->runDueCron(new \DateTimeImmutable('2026-10-29T09:00:00Z')));
        self::assertSame(['* * * * *', '0 9 * * *'], array_column($this->cronCalls(), 'hook'));
    }

    public function testNativeWidgetBadgesAreAskedAsTheViewer(): void
    {
        $admin = $this->loginAdmin();
        $this->activate(AuditPlugin::SLUG);

        $this->visit('/support/admin/plugins');
        $shared = $this->client->getRequest()->attributes->get('_escalated_shared');
        self::assertIsArray($shared);
        $widgets = array_column($shared['plugins']['dashboardWidgets'], null, 'component');

        self::assertSame('for '.$admin->getId(), $widgets['AuditInbox']['badge']);
        self::assertSame(7, $widgets['AuditCount']['badge']);
        self::assertArrayHasKey('badge', $widgets['AuditBroken']);
        self::assertNull($widgets['AuditBroken']['badge'], 'a failing badge is null');
        self::assertArrayNotHasKey('badge', $widgets['AuditPlain'], 'no badge function, no badge key');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cronCalls(): array
    {
        return array_values(array_filter(PluginRecorder::$calls, static fn (array $c): bool => AuditPlugin::NAME === $c['plugin'] && 'cron' === $c['call']));
    }
}
