<?php

declare(strict_types=1);

namespace Escalated\Symfony\Scheduling;

use Escalated\Symfony\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Whether the recurring jobs run. The SLA check (`escalated:check-sla-breaches`,
 * every minute) records when it last ran, in the settings table; when that is
 * missing or older than {@see STALE_AFTER_MINUTES}, nothing runs the jobs and
 * SLA breaches, escalations, automations and snooze wake-ups silently stop.
 *
 * {@see status()} is the `scheduler` prop the admin settings and reports pages
 * send: `{healthy, last_run_at, message, commands}`, or null when it cannot be
 * told (the settings table is not migrated yet).
 */
class SchedulerHeartbeat
{
    public const SETTING = 'scheduler_heartbeat_at';

    public const STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly SettingsService $settings,
        #[Autowire('%escalated.enable_newsletters%')]
        private readonly bool $newsletters = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Records a run of the SLA check. Never fails the job it is called from.
     */
    public function record(?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();

        try {
            $this->settings->set(self::SETTING, $at->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
        } catch (\Throwable $e) {
            $this->logger?->warning('[Escalated] Could not record the scheduler heartbeat: '.$e->getMessage());
        }
    }

    public function lastRunAt(): ?\DateTimeImmutable
    {
        $raw = $this->settings->get(self::SETTING);
        if (null === $raw || '' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array{healthy: bool, last_run_at: string|null, message: string, commands: list<array{command: string, interval: string}>}|null
     */
    public function status(?\DateTimeImmutable $now = null): ?array
    {
        try {
            $last = $this->lastRunAt();
        } catch (\Throwable) {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        $healthy = null !== $last && $last >= $now->modify(sprintf('-%d minutes', self::STALE_AFTER_MINUTES));

        if ($healthy) {
            $message = 'The recurring jobs are running.';
        } else {
            $message = sprintf(
                'The recurring jobs are not running: %s %s. SLA breaches, escalation rules, automations and snooze '
                .'wake-ups only happen when these commands run. Schedule each command below with cron, or turn on '
                .'escalated.scheduling.auto_register and run "php bin/console messenger:consume scheduler_escalated".',
                RecurringJobs::SLA_CHECK,
                null === $last
                    ? 'has never run'
                    : sprintf('last ran at %s, more than %d minutes ago', $last->format(\DATE_ATOM), self::STALE_AFTER_MINUTES),
            );
        }

        return [
            'healthy' => $healthy,
            'last_run_at' => $last?->format(\DATE_ATOM),
            'message' => $message,
            'commands' => RecurringJobs::commands($this->newsletters),
        ];
    }
}
