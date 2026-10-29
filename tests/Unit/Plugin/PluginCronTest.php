<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Unit\Plugin;

use Escalated\Symfony\Plugin\PluginCron;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginCronTest extends TestCase
{
    /**
     * @return iterable<array{string, string, bool}>
     */
    public static function expressions(): iterable
    {
        yield ['* * * * *', '2026-10-29T13:37:00Z', true];
        yield ['37 13 * * *', '2026-10-29T13:37:45Z', true];
        yield ['38 13 * * *', '2026-10-29T13:37:00Z', false];
        yield ['*/15 * * * *', '2026-10-29T13:45:00Z', true];
        yield ['*/15 * * * *', '2026-10-29T13:46:00Z', false];
        yield ['0-10/5 9-17 * * *', '2026-10-29T09:10:00Z', true];
        yield ['0-10/5 9-17 * * *', '2026-10-29T18:10:00Z', false];
        yield ['1,2,3 * * * *', '2026-10-29T13:02:00Z', true];
        // 2026-10-29 is a Thursday; 2026-11-01 a Sunday.
        yield ['0 9 * * thu', '2026-10-29T09:00:00Z', true];
        yield ['0 9 * * MON-WED', '2026-10-29T09:00:00Z', false];
        yield ['0 0 * * 7', '2026-11-01T00:00:00Z', true];
        yield ['0 0 1 nov *', '2026-11-01T00:00:00Z', true];
        yield ['0 0 13 * 4', '2026-10-29T00:00:00Z', true];
        yield ['0 0 13 * 5', '2026-10-29T00:00:00Z', false];
        yield ['0 0 29 * *', '2026-10-29T00:00:00Z', true];
        yield ['0 9 * * *', '2026-10-29T05:00:00-04:00', true];
        yield ['@hourly', '2026-10-29T13:00:00Z', true];
        yield ['@daily', '2026-10-29T13:00:00Z', false];
        yield ['@weekly', '2026-11-01T00:00:00Z', true];
    }

    #[DataProvider('expressions')]
    public function testIsDueInTheMinuteInUtc(string $expression, string $at, bool $due): void
    {
        self::assertSame($due, PluginCron::due($expression, new \DateTimeImmutable($at)));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function unreadable(): iterable
    {
        foreach (['', '* * * *', '61 * * * *', '* * * * * *', 'a b c d e', '*/0 * * * *', '5-1 * * * *'] as $bad) {
            yield [$bad];
        }
    }

    #[DataProvider('unreadable')]
    public function testRefusesWhatItCannotRead(string $expression): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PluginCron::due($expression, new \DateTimeImmutable());
    }
}
