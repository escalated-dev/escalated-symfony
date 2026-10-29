<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

/**
 * A native plugin with cron schedules: the native form of the SDK's `cron`.
 * List the schedules in the manifest's `cronSchedules` (five-field cron
 * expressions, UTC, or `@hourly`-style shorthands); `escalated:plugins:cron`,
 * run every minute, calls {@see handleCron()} once for each schedule that is
 * due, while the plugin is active, with no acting user. A schedule that
 * throws is logged and never stops the others.
 */
interface CronPluginInterface
{
    /**
     * `$schedule` is the expression as written in `cronSchedules`.
     */
    public function handleCron(string $schedule, PluginContext $ctx): void;
}
