<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures\Plugin;

use Escalated\Symfony\Plugin\AbstractPlugin;
use Escalated\Symfony\Plugin\CronPluginInterface;
use Escalated\Symfony\Plugin\PluginContext;
use Escalated\Symfony\Plugin\WidgetBadgePluginInterface;

/**
 * A second native plugin, to see what one plugin is and is not given while
 * another is active: actions, a lower-priority filter, its own store. Also
 * the native cron schedules (one unreadable) and widget badges: `AuditInbox`
 * answers "for " plus the viewer's id, `AuditCount` 7, `AuditBroken` throws,
 * `AuditPlain` has no badge.
 */
final class AuditPlugin extends AbstractPlugin implements CronPluginInterface, WidgetBadgePluginInterface
{
    public const NAME = '@acme/escalated-plugin-audit';
    public const SLUG = 'acme--escalated-plugin-audit';

    public function manifest(): array
    {
        return [
            'name' => self::NAME,
            'version' => '0.1.0',
            'filterHooks' => [['hook' => 'demo.value', 'priority' => 5]],
            'widgets' => [
                ['component' => 'AuditPlain', 'label' => 'Plain', 'order' => 10],
                ['component' => 'AuditInbox', 'label' => 'Inbox', 'order' => 11, 'hasBadge' => true],
                ['component' => 'AuditCount', 'label' => 'Count', 'order' => 12, 'hasBadge' => true],
                ['component' => 'AuditBroken', 'label' => 'Broken', 'order' => 13, 'hasBadge' => true],
            ],
            'cronSchedules' => ['* * * * *', '0 9 * * *', '30 9 * * *', 'not a schedule'],
        ];
    }

    public function onActivate(PluginContext $ctx): void
    {
        PluginRecorder::record(self::NAME, 'onActivate');
    }

    public function onDeactivate(PluginContext $ctx): void
    {
        PluginRecorder::record(self::NAME, 'onDeactivate');
    }

    public function handleAction(string $hook, array $event, PluginContext $ctx): void
    {
        PluginRecorder::record(self::NAME, 'action', ['hook' => $hook, 'event' => $event]);
    }

    public function applyFilter(string $hook, mixed $value, PluginContext $ctx): mixed
    {
        PluginRecorder::record(self::NAME, 'filter', ['hook' => $hook]);

        return $value.'|audit';
    }

    public function handleCron(string $schedule, PluginContext $ctx): void
    {
        PluginRecorder::record(self::NAME, 'cron', ['hook' => $schedule, 'event' => ['user' => $ctx->currentUser()]]);
        $ctx->storeInsert('cron', ['schedule' => $schedule]);
    }

    public function widgetBadge(string $component, PluginContext $ctx): int|float|string|null
    {
        return match ($component) {
            'AuditInbox' => 'for '.($ctx->currentUser()['id'] ?? 'nobody'),
            'AuditCount' => 7,
            default => throw new \RuntimeException('badge failed'),
        };
    }
}
