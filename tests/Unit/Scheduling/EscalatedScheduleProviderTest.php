<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Unit\Scheduling;

use Escalated\Symfony\EscalatedBundle;
use Escalated\Symfony\Scheduling\EscalatedScheduleProvider;
use Escalated\Symfony\Scheduling\RecurringJobs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

/**
 * `scheduling.auto_register`: the recurring jobs as the `escalated` Symfony
 * Scheduler schedule, so a host needs no cron entries. Off by default.
 */
final class EscalatedScheduleProviderTest extends TestCase
{
    public function testTheScheduleRunsEveryRecurringCommandAtItsInterval(): void
    {
        $schedule = (new EscalatedScheduleProvider())->getSchedule();

        $commands = [];
        foreach ($schedule->getRecurringMessages() as $recurring) {
            \assert($recurring instanceof RecurringMessage);
            $messages = iterator_to_array($recurring->getMessages(new MessageContext('escalated', 'id', $recurring->getTrigger(), new \DateTimeImmutable())), false);
            self::assertCount(1, $messages);
            self::assertInstanceOf(RunCommandMessage::class, $messages[0]);
            $commands[$messages[0]->input] = (string) $recurring->getTrigger();
        }

        self::assertSame(array_keys(RecurringJobs::all()), array_keys($commands));
        self::assertSame('every 1 minute', $commands['escalated:check-sla-breaches']);
        self::assertSame('every 1 minute', $commands['escalated:wake-snoozed-tickets']);
        self::assertSame('every 5 minutes', $commands['escalated:escalations:run']);
        self::assertSame('every 5 minutes', $commands['escalated:automations:run']);
        self::assertArrayNotHasKey('escalated:newsletters:dispatch', $commands);
    }

    public function testNewslettersAreScheduledOnlyWhenEnabled(): void
    {
        $inputs = [];
        foreach ((new EscalatedScheduleProvider(true))->getSchedule()->getRecurringMessages() as $recurring) {
            foreach ($recurring->getMessages(new MessageContext('escalated', 'id', $recurring->getTrigger(), new \DateTimeImmutable())) as $message) {
                $inputs[] = $message->input;
            }
        }

        self::assertContains('escalated:newsletters:dispatch', $inputs);
    }

    public function testTheProviderIsRegisteredOnlyWhenAutoRegisterIsOn(): void
    {
        $off = new ContainerBuilder();
        EscalatedBundle::setScheduling($off, []);
        EscalatedBundle::setScheduling($off, ['auto_register' => false]);
        self::assertFalse($off->hasDefinition(EscalatedScheduleProvider::class));

        $on = new ContainerBuilder();
        EscalatedBundle::setScheduling($on, ['auto_register' => true]);
        self::assertTrue($on->hasDefinition(EscalatedScheduleProvider::class));
        self::assertSame([['name' => 'escalated']], $on->getDefinition(EscalatedScheduleProvider::class)->getTag('scheduler.schedule_provider'));
    }
}
