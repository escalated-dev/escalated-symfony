<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Fixtures\Plugin;

use Escalated\Symfony\Plugin\AbstractPlugin;
use Escalated\Symfony\Plugin\PluginContext;

/**
 * A second native plugin, to see what one plugin is and is not given while
 * another is active: actions, a lower-priority filter, its own store.
 */
final class AuditPlugin extends AbstractPlugin
{
    public const NAME = '@acme/escalated-plugin-audit';
    public const SLUG = 'acme--escalated-plugin-audit';

    public function manifest(): array
    {
        return [
            'name' => self::NAME,
            'version' => '0.1.0',
            'filterHooks' => [['hook' => 'demo.value', 'priority' => 5]],
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
}
