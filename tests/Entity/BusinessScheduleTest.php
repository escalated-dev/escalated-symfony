<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\BusinessSchedule;
use Escalated\Symfony\Entity\Holiday;
use PHPUnit\Framework\TestCase;

class BusinessScheduleTest extends TestCase
{
    public function testDefaultHoursAreSet(): void
    {
        $schedule = new BusinessSchedule();
        $hours = $schedule->getHours();

        $this->assertArrayHasKey('monday', $hours);
        $this->assertTrue($hours['monday']['enabled']);
        $this->assertFalse($hours['saturday']['enabled']);
        $this->assertSame('09:00', $hours['wednesday']['start']);
        $this->assertSame('17:00', $hours['wednesday']['end']);
    }

    public function testIsWithinBusinessHoursOnWeekday(): void
    {
        $schedule = new BusinessSchedule();
        $schedule->setTimezone('UTC');

        // Create a known Monday at 10:00 UTC
        $monday = new \DateTimeImmutable('2026-04-06 10:00:00', new \DateTimeZone('UTC'));
        $this->assertTrue($schedule->isWithinBusinessHours($monday));
    }

    public function testIsOutsideBusinessHoursOnWeekend(): void
    {
        $schedule = new BusinessSchedule();
        $schedule->setTimezone('UTC');

        // Create a known Saturday
        $saturday = new \DateTimeImmutable('2026-04-04 10:00:00', new \DateTimeZone('UTC'));
        $this->assertFalse($schedule->isWithinBusinessHours($saturday));
    }

    public function testHolidayExcludesDay(): void
    {
        $schedule = new BusinessSchedule();
        $schedule->setTimezone('UTC');

        $holiday = new Holiday();
        $holiday->setName('Test Holiday');
        $holiday->setDate(new \DateTimeImmutable('2026-04-06'));
        $schedule->addHoliday($holiday);

        $monday = new \DateTimeImmutable('2026-04-06 10:00:00', new \DateTimeZone('UTC'));
        $this->assertFalse($schedule->isWithinBusinessHours($monday));
    }
}
