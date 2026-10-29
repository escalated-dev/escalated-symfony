<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

/**
 * Five-field cron expressions (`minute hour day-of-month month day-of-week`),
 * as the plugin SDK's `cron` keys are written, evaluated in UTC. Supports `*`,
 * numbers, `a-b` ranges, `/step`, comma lists, month and weekday names, 7 as
 * Sunday, and the `@hourly`-style shorthands. When both day fields are
 * restricted, either one matching is enough, as cron does.
 */
final class PluginCron
{
    private const SHORTHANDS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    private const FIELDS = [
        [0, 59, [], 0],
        [0, 23, [], 0],
        [1, 31, [], 0],
        [1, 12, ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'], 1],
        [0, 7, ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], 0],
    ];

    /**
     * Whether `$expression` is due in the minute containing `$at` (UTC).
     *
     * @throws \InvalidArgumentException when the expression cannot be read
     */
    public static function due(string $expression, \DateTimeInterface $at): bool
    {
        $text = trim($expression);
        $text = self::SHORTHANDS[strtolower($text)] ?? $text;
        $parts = '' === $text ? [] : preg_split('/\s+/', $text);
        if (!\is_array($parts) || 5 !== \count($parts)) {
            throw self::invalid($expression);
        }

        $values = [];
        $any = [];
        foreach ($parts as $i => $part) {
            [$values[$i], $any[$i]] = self::parse($part, self::FIELDS[$i], $expression);
        }
        if (isset($values[4][7])) {
            $values[4][0] = true;
        }

        $utc = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));
        if (!isset($values[0][(int) $utc->format('i')], $values[1][(int) $utc->format('G')], $values[3][(int) $utc->format('n')])) {
            return false;
        }
        $dom = isset($values[2][(int) $utc->format('j')]);
        $dow = isset($values[4][(int) $utc->format('w')]);

        return !$any[2] && !$any[4] ? $dom || $dow : $dom && $dow;
    }

    /**
     * @param array{int, int, list<string>, int} $field
     *
     * @return array{array<int, true>, bool}
     */
    private static function parse(string $text, array $field, string $expression): array
    {
        [$min, $max] = $field;
        $values = [];
        $any = false;
        foreach (explode(',', $text) as $part) {
            $pieces = explode('/', $part);
            if (\count($pieces) > 2) {
                throw self::invalid($expression);
            }
            $step = 2 === \count($pieces) ? self::number($pieces[1], 1, \PHP_INT_MAX, $expression) : 1;
            $range = $pieces[0];
            if ('*' === $range) {
                [$from, $to] = [$min, $max];
                $any = 1 === \count($pieces);
            } elseif (str_contains($range, '-')) {
                $ends = explode('-', $range);
                if (2 !== \count($ends)) {
                    throw self::invalid($expression);
                }
                $from = self::value($ends[0], $field, $expression);
                $to = self::value($ends[1], $field, $expression);
                if ($from > $to) {
                    throw self::invalid($expression);
                }
            } else {
                $from = self::value($range, $field, $expression);
                $to = 2 === \count($pieces) ? $max : $from;
            }
            for ($n = $from; $n <= $to; $n += $step) {
                $values[$n] = true;
            }
        }

        return [$values, $any];
    }

    /**
     * @param array{int, int, list<string>, int} $field
     */
    private static function value(string $token, array $field, string $expression): int
    {
        $name = array_search(strtolower($token), $field[2], true);
        if (false !== $name) {
            return $name + $field[3];
        }

        return self::number($token, $field[0], $field[1], $expression);
    }

    private static function number(string $token, int $min, int $max, string $expression): int
    {
        if ('' === $token || \strlen($token) > 9 || !ctype_digit($token)) {
            throw self::invalid($expression);
        }
        $n = (int) $token;
        if ($n < $min || $n > $max) {
            throw self::invalid($expression);
        }

        return $n;
    }

    private static function invalid(string $expression): \InvalidArgumentException
    {
        return new \InvalidArgumentException(sprintf('Invalid cron expression "%s".', $expression));
    }
}
