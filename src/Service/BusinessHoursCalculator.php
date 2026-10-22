<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Escalated\Symfony\Entity\BusinessSchedule;

/**
 * escalated-laravel's BusinessHoursCalculator, counted in minutes.
 *
 * Opening hours are wall-clock times in the schedule's time zone, but time is
 * counted in real elapsed minutes, so a window on a daylight-saving change day
 * is an hour shorter or longer than its wall-clock span. A window belongs to
 * the local date it opens on: a holiday on that date closes it, overnight part
 * included. `24:00` is the next midnight; an end before the start is an
 * overnight window; an equal start and end is closed. A schedule with no usable
 * window falls back to calendar time, so a policy never loses its deadline.
 */
class BusinessHoursCalculator
{
    /** A schedule whose windows are all closed by holidays for ten years has no hours. */
    private const MAX_SCAN_DAYS = 3660;

    private const WEEKDAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * The instant `$minutes` of business time after `$from`, in UTC.
     */
    public function addBusinessMinutes(\DateTimeInterface $from, float $minutes, BusinessSchedule $schedule): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $start = \DateTimeImmutable::createFromInterface($from)->setTimezone($utc);
        $remaining = (int) round($minutes * 60);
        if ($remaining <= 0) {
            return $start;
        }

        $calendar = $start->modify(sprintf('+%d seconds', $remaining));
        if (!$this->hasOpenDay($schedule)) {
            return $calendar;
        }

        $zone = $this->zone($schedule);
        $cursor = $start->getTimestamp();
        // Start a day early: an overnight window opened yesterday may still be open.
        $day = $start->setTimezone($zone)->setTime(0, 0)->modify('-1 day')->format('Y-m-d');

        for ($i = 0; $i < self::MAX_SCAN_DAYS; ++$i) {
            $window = $this->windowOn($day, $schedule, $zone);
            if (null !== $window && $window[1] > $cursor) {
                $from = max($window[0], $cursor);
                $available = $window[1] - $from;
                if ($remaining <= $available) {
                    return (new \DateTimeImmutable('@'.($from + $remaining)))->setTimezone($utc);
                }
                $remaining -= $available;
                $cursor = $window[1];
            }
            $day = self::nextDate($day);
        }

        return $calendar;
    }

    public function addBusinessHours(\DateTimeInterface $from, float $hours, BusinessSchedule $schedule): \DateTimeImmutable
    {
        return $this->addBusinessMinutes($from, $hours * 60, $schedule);
    }

    /**
     * Whether `$at` falls inside one of the schedule's opening windows.
     */
    public function isWithinBusinessHours(\DateTimeInterface $at, BusinessSchedule $schedule): bool
    {
        $zone = $this->zone($schedule);
        $instant = $at->getTimestamp();
        $today = \DateTimeImmutable::createFromInterface($at)->setTimezone($zone)->format('Y-m-d');

        foreach ([self::previousDate($today), $today] as $day) {
            $window = $this->windowOn($day, $schedule, $zone);
            if (null !== $window && $instant >= $window[0] && $instant < $window[1]) {
                return true;
            }
        }

        return false;
    }

    public static function isValidTimezone(mixed $name): bool
    {
        return \is_string($name) && '' !== $name && \in_array($name, self::timezones(), true);
    }

    /**
     * @return list<string>
     */
    public static function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    /**
     * A day's hours in the shape the shared pages use: `start` and `end` as
     * "HH:MM", both empty when the day is closed. Rows from before the admin
     * pages carry an `enabled` flag; `enabled: false` is a closed day.
     *
     * @return array{start: string, end: string}|null null when closed
     */
    public static function dayBounds(mixed $hours): ?array
    {
        if (!\is_array($hours) || false === ($hours['enabled'] ?? true)) {
            return null;
        }
        $start = self::clockMinutes($hours['start'] ?? null);
        $end = self::clockMinutes($hours['end'] ?? null);
        if (null === $start || null === $end || $start >= 1440 || $start === $end) {
            return null;
        }

        return ['start' => (string) $hours['start'], 'end' => (string) $hours['end']];
    }

    private function zone(BusinessSchedule $schedule): \DateTimeZone
    {
        $name = $schedule->getTimezone();

        return new \DateTimeZone(self::isValidTimezone($name) ? $name : 'UTC');
    }

    private function hasOpenDay(BusinessSchedule $schedule): bool
    {
        $hours = $schedule->getHours();
        foreach (self::WEEKDAYS as $key) {
            if (null !== self::dayBounds($hours[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The opening window that starts on the local date `$day` (Y-m-d), as unix
     * timestamps, or null when that date is closed or a holiday.
     *
     * @return array{0: int, 1: int}|null
     */
    private function windowOn(string $day, BusinessSchedule $schedule, \DateTimeZone $zone): ?array
    {
        $weekday = self::WEEKDAYS[(int) (new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->format('N') - 1];
        $bounds = self::dayBounds($schedule->getHours()[$weekday] ?? null);
        if (null === $bounds || $this->isHoliday($day, $schedule)) {
            return null;
        }

        $open = (int) self::clockMinutes($bounds['start']);
        $close = (int) self::clockMinutes($bounds['end']);
        $start = $this->wallClock($day, $open, $zone);
        $end = $this->wallClock($close <= $open ? self::nextDate($day) : $day, $close, $zone);

        return $end > $start ? [$start, $end] : null;
    }

    private function isHoliday(string $day, BusinessSchedule $schedule): bool
    {
        foreach ($schedule->getHolidays() as $holiday) {
            $date = $holiday->getDate()->format('Y-m-d');
            if ($holiday->isRecurring() ? substr($date, 5) === substr($day, 5) : $date === $day) {
                return true;
            }
        }

        return false;
    }

    /**
     * The instant the clock in `$zone` reads `$minutes` past midnight on the
     * local date `$day`. 1440 is the next midnight. A wall time skipped by a
     * daylight-saving change resolves to the moment the clocks go forward.
     */
    private function wallClock(string $day, int $minutes, \DateTimeZone $zone): int
    {
        if ($minutes >= 1440) {
            $day = self::nextDate($day);
            $minutes -= 1440;
        }
        $label = sprintf('%s %02d:%02d', $day, intdiv($minutes, 60), $minutes % 60);
        $t = new \DateTimeImmutable($label, $zone);
        if ($t->format('Y-m-d H:i') === $label) {
            return $t->getTimestamp();
        }

        // In a gap, PHP moves the wall time forward by the size of the jump.
        // Walk back to the first instant whose wall time is not before the one
        // asked for: the moment of the change.
        $instant = $t->getTimestamp() - ($t->getTimestamp() % 60);
        for ($i = 0; $i < 1440; ++$i) {
            $previous = (new \DateTimeImmutable('@'.($instant - 60)))->setTimezone($zone);
            if ($previous->format('Y-m-d H:i') < $label) {
                break;
            }
            $instant -= 60;
        }

        return $instant;
    }

    /** Parses "HH:MM", 00:00 to 24:00, into minutes after midnight. */
    private static function clockMinutes(mixed $value): ?int
    {
        if (!\is_string($value) || 1 !== preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $value, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 24 || $min > 59 || (24 === $h && 0 !== $min)) {
            return null;
        }

        return $h * 60 + $min;
    }

    private static function nextDate(string $day): string
    {
        return (new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    }

    private static function previousDate(string $day): string
    {
        return (new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->modify('-1 day')->format('Y-m-d');
    }
}
