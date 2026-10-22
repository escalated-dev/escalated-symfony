<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Entity\BusinessSchedule;
use Escalated\Symfony\Entity\Holiday;
use Escalated\Symfony\Service\BusinessHoursCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The same cases as the Go, Phoenix and Adonis ports, so a deadline comes out
 * the same on every backend.
 */
final class BusinessHoursCalculatorTest extends TestCase
{
    private const NINE_TO_FIVE = ['start' => '09:00', 'end' => '17:00'];
    private const CLOSED = ['start' => '', 'end' => ''];

    /**
     * @param array<string, array<string, mixed>>|null    $hours
     * @param list<array{date: string, recurring?: bool}> $holidays
     */
    private function schedule(?array $hours = null, string $timezone = 'UTC', array $holidays = []): BusinessSchedule
    {
        $schedule = (new BusinessSchedule())->setTimezone($timezone)->setHours($hours ?? [
            'monday' => self::NINE_TO_FIVE,
            'tuesday' => self::NINE_TO_FIVE,
            'wednesday' => self::NINE_TO_FIVE,
            'thursday' => self::NINE_TO_FIVE,
            'friday' => self::NINE_TO_FIVE,
            'saturday' => self::CLOSED,
            'sunday' => self::CLOSED,
        ]);
        foreach ($holidays as $h) {
            $schedule->addHoliday((new Holiday())->setName('Off')->setDate(new \DateTimeImmutable($h['date']))->setIsRecurring($h['recurring'] ?? false));
        }

        return $schedule;
    }

    private function add(BusinessSchedule $schedule, string $from, float $minutes): string
    {
        return (new BusinessHoursCalculator())->addBusinessMinutes(new \DateTimeImmutable($from), $minutes, $schedule)->format('Y-m-d\TH:i:s\Z');
    }

    private function within(BusinessSchedule $schedule, string $at): bool
    {
        return (new BusinessHoursCalculator())->isWithinBusinessHours(new \DateTimeImmutable($at), $schedule);
    }

    public function testAddsMinutesInsideOneOpenWindow(): void
    {
        // 2026-10-19 is a Monday.
        self::assertSame('2026-10-19T12:00:00Z', $this->add($this->schedule(), '2026-10-19T10:00:00Z', 120));
    }

    public function testCarriesWhatIsLeftIntoTheNextOpenDay(): void
    {
        self::assertSame('2026-10-20T10:00:00Z', $this->add($this->schedule(), '2026-10-19T16:00:00Z', 120));
    }

    public function testSkipsTheWeekend(): void
    {
        self::assertSame('2026-10-26T10:00:00Z', $this->add($this->schedule(), '2026-10-23T16:00:00Z', 120));
    }

    public function testStartsAtTheNextOpeningWhenCreatedAfterHours(): void
    {
        self::assertSame('2026-10-26T10:00:00Z', $this->add($this->schedule(), '2026-10-23T20:00:00Z', 60));
        self::assertSame('2026-10-26T09:30:00Z', $this->add($this->schedule(), '2026-10-24T12:00:00Z', 30));
    }

    public function testSkipsAOneOffHoliday(): void
    {
        $s = $this->schedule(null, 'UTC', [['date' => '2026-10-20']]);
        self::assertSame('2026-10-21T10:00:00Z', $this->add($s, '2026-10-19T16:00:00Z', 120));
        // The same date in another year is an ordinary day.
        self::assertSame('2027-10-20T10:00:00Z', $this->add($s, '2027-10-19T16:00:00Z', 120));
    }

    public function testSkipsARecurringHolidayEveryYear(): void
    {
        $s = $this->schedule(null, 'UTC', [['date' => '2020-12-25', 'recurring' => true]]);
        self::assertSame('2026-12-28T10:00:00Z', $this->add($s, '2026-12-24T16:00:00Z', 120));
    }

    public function testCountsAnOvernightWindowThatCrossesMidnight(): void
    {
        $s = $this->schedule(['monday' => ['start' => '22:00', 'end' => '06:00'], 'tuesday' => ['start' => '22:00', 'end' => '06:00']]);
        self::assertSame('2026-10-20T02:00:00Z', $this->add($s, '2026-10-19T23:00:00Z', 180));
        // Tuesday 01:00 is still inside Monday's window: 5h left of it, then Tuesday 22:00.
        self::assertSame('2026-10-20T23:00:00Z', $this->add($s, '2026-10-20T01:00:00Z', 360));
    }

    public function testClosesTheOvernightWindowThatOpensOnAHoliday(): void
    {
        $s = $this->schedule(['monday' => ['start' => '22:00', 'end' => '06:00'], 'tuesday' => ['start' => '22:00', 'end' => '06:00']], 'UTC', [['date' => '2026-10-19']]);
        self::assertSame('2026-10-20T23:00:00Z', $this->add($s, '2026-10-19T23:00:00Z', 60));
    }

    public function testTreats2400AsTheNextMidnight(): void
    {
        $s = $this->schedule(['monday' => ['start' => '20:00', 'end' => '24:00']]);
        self::assertSame('2026-10-20T00:00:00Z', $this->add($s, '2026-10-19T22:00:00Z', 120));
        self::assertSame('2026-10-26T21:00:00Z', $this->add($s, '2026-10-19T22:00:00Z', 180));
    }

    public function testReadsTheHoursInTheScheduleTimeZone(): void
    {
        $s = $this->schedule(null, 'America/Toronto');
        self::assertSame('2026-10-19T14:00:00Z', $this->add($s, '2026-10-19T12:00:00Z', 60));
        self::assertSame('2026-10-26T13:30:00Z', $this->add($s, '2026-10-23T20:30:00Z', 60));
    }

    public function testUsesTheLocalDateForWeekdaysAndHolidays(): void
    {
        $s = $this->schedule(null, 'Asia/Tokyo', [['date' => '2026-10-20']]);
        // 2026-10-19T23:00Z is Tuesday 08:00 in Tokyo, a holiday.
        self::assertSame('2026-10-21T01:00:00Z', $this->add($s, '2026-10-19T23:00:00Z', 60));
    }

    public function testCountsRealMinutesAcrossTheSpringForwardChange(): void
    {
        $s = $this->schedule(['sunday' => ['start' => '00:00', 'end' => '06:00'], 'monday' => self::NINE_TO_FIVE], 'America/New_York');
        self::assertSame('2026-03-08T10:00:00Z', $this->add($s, '2026-03-08T05:00:00Z', 300));
        self::assertSame('2026-03-09T14:00:00Z', $this->add($s, '2026-03-08T05:00:00Z', 360));
    }

    public function testOpensAtTheClockChangeWhenTheOpeningTimeDoesNotExist(): void
    {
        $s = $this->schedule(['sunday' => ['start' => '02:30', 'end' => '04:00']], 'America/New_York');
        self::assertSame('2026-03-08T07:30:00Z', $this->add($s, '2026-03-08T00:00:00Z', 30));
        self::assertSame('2026-03-08T08:00:00Z', $this->add($s, '2026-03-08T00:00:00Z', 60));
    }

    public function testCountsRealMinutesAcrossTheFallBackChange(): void
    {
        $s = $this->schedule(['sunday' => ['start' => '00:00', 'end' => '03:00'], 'monday' => self::NINE_TO_FIVE], 'America/New_York');
        self::assertSame('2026-11-01T08:00:00Z', $this->add($s, '2026-11-01T04:00:00Z', 240));
        self::assertSame('2026-11-02T14:30:00Z', $this->add($s, '2026-11-01T04:00:00Z', 270));
    }

    public function testFallsBackToCalendarTimeWhenNoDayIsOpen(): void
    {
        $s = $this->schedule(['monday' => self::CLOSED, 'tuesday' => ['start' => '09:00', 'end' => '09:00']]);
        self::assertSame('2026-10-19T11:30:00Z', $this->add($s, '2026-10-19T10:00:00Z', 90));
    }

    public function testFallsBackToUtcForAnUnknownTimeZone(): void
    {
        self::assertSame('2026-10-19T11:00:00Z', $this->add($this->schedule(null, 'Not/AZone'), '2026-10-19T10:00:00Z', 60));
    }

    public function testReturnsTheStartForZeroMinutes(): void
    {
        self::assertSame('2026-10-24T10:00:00Z', $this->add($this->schedule(), '2026-10-24T10:00:00Z', 0));
    }

    public function testReadsTheEnabledFlagOfOlderRows(): void
    {
        // The shape BusinessSchedule was created with before the admin pages.
        $s = $this->schedule(['monday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => false], 'tuesday' => ['start' => '09:00', 'end' => '17:00', 'enabled' => true]]);
        self::assertSame('2026-10-20T10:00:00Z', $this->add($s, '2026-10-19T10:00:00Z', 60));
    }

    public function testTellsWhetherAnInstantIsInsideBusinessHours(): void
    {
        $s = $this->schedule(null, 'America/Toronto', [['date' => '2026-10-20']]);
        self::assertTrue($this->within($s, '2026-10-19T14:00:00Z'));
        self::assertFalse($this->within($s, '2026-10-19T12:59:00Z'));
        self::assertFalse($this->within($s, '2026-10-19T21:00:00Z'));
        self::assertFalse($this->within($s, '2026-10-20T14:00:00Z'));
        self::assertFalse($this->within($s, '2026-10-24T14:00:00Z'));
    }

    public function testCountsTheEarlyHoursOfAnOvernightWindowAsOpen(): void
    {
        $s = $this->schedule(['monday' => ['start' => '22:00', 'end' => '06:00']]);
        self::assertTrue($this->within($s, '2026-10-20T03:00:00Z'));
        self::assertFalse($this->within($s, '2026-10-20T07:00:00Z'));
    }
}
