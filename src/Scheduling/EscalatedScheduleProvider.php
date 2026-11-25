<?php

declare(strict_types=1);

namespace Escalated\Symfony\Scheduling;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The recurring jobs as a Symfony Scheduler schedule named `escalated`,
 * registered only while `escalated.scheduling.auto_register` is on (it needs
 * symfony/scheduler and symfony/messenger). A worker runs it:
 *
 *     php bin/console messenger:consume scheduler_escalated
 *
 * Each job is the console command a cron entry would run, sent as a
 * RunCommandMessage, so both ways of scheduling run the same code.
 */
final class EscalatedScheduleProvider implements ScheduleProviderInterface
{
    public const NAME = 'escalated';

    private ?Schedule $schedule = null;

    public function __construct(private readonly bool $newsletters = false)
    {
    }

    public function getSchedule(): Schedule
    {
        if (null !== $this->schedule) {
            return $this->schedule;
        }

        $schedule = new Schedule();
        foreach (RecurringJobs::all($this->newsletters) as $command => [, $frequency]) {
            $schedule->add(RecurringMessage::every($frequency, new RunCommandMessage($command)));
        }

        return $this->schedule = $schedule;
    }
}
