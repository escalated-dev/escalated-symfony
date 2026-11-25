<?php

declare(strict_types=1);

namespace Escalated\Symfony\Scheduling;

/**
 * The console commands a host must run on a schedule, and how often: the one
 * list the docs, the scheduler warning and the Symfony Scheduler schedule
 * ({@see EscalatedScheduleProvider}) all use.
 */
final class RecurringJobs
{
    public const SLA_CHECK = 'escalated:check-sla-breaches';

    /**
     * Command name => [interval as shown to admins, Symfony Scheduler frequency].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const CORE = [
        self::SLA_CHECK => ['every minute', '1 minute'],
        'escalated:process-delayed-actions' => ['every minute', '1 minute'],
        'escalated:wake-snoozed-tickets' => ['every minute', '1 minute'],
        'escalated:escalations:run' => ['every 5 minutes', '5 minutes'],
        'escalated:automations:run' => ['every 5 minutes', '5 minutes'],
        'escalated:chat:close-idle' => ['every 5 minutes', '5 minutes'],
        'escalated:plugins:cron' => ['every minute', '1 minute'],
        'escalated:purge-expired' => ['daily', '1 day'],
    ];

    private const NEWSLETTERS = ['escalated:newsletters:dispatch' => ['every minute', '1 minute']];

    /**
     * @return list<array{command: string, interval: string}>
     */
    public static function commands(bool $newsletters = false): array
    {
        $commands = [];
        foreach (self::all($newsletters) as $command => [$interval]) {
            $commands[] = ['command' => 'php bin/console '.$command, 'interval' => $interval];
        }

        return $commands;
    }

    /**
     * @return array<string, array{0: string, 1: string}> command name => [interval, scheduler frequency]
     */
    public static function all(bool $newsletters = false): array
    {
        return $newsletters ? [...self::CORE, ...self::NEWSLETTERS] : self::CORE;
    }
}
