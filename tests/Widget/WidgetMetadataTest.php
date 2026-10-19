<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Widget;

use Escalated\Symfony\Widget\WidgetMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Host parameters an embedding page sends with a widget ticket or chat. They
 * come from the visitor's browser, so only a flat map of short scalars is kept.
 */
final class WidgetMetadataTest extends TestCase
{
    public function testKeepsAFlatMapOfShortScalars(): void
    {
        $clean = WidgetMetadata::sanitize([
            'order_number' => ' A-1001 ',
            'items' => 3,
            'total' => 129.5,
            'gift' => true,
            'nested' => ['not' => 'kept'],
            'empty' => null,
            'bad key!' => 'dropped',
            'note' => str_repeat('a', 600),
        ], null);

        self::assertSame([
            'order_number' => 'A-1001',
            'items' => 3,
            'total' => 129.5,
            'gift' => true,
            'note' => str_repeat('a', 500),
        ], $clean);
    }

    public function testKeepsAtMostTwentyFiveEntries(): void
    {
        $metadata = [];
        for ($i = 0; $i < 40; ++$i) {
            $metadata['k'.$i] = $i;
        }

        self::assertCount(25, WidgetMetadata::sanitize($metadata, null) ?? []);
    }

    public function testKeepsOnlyAWebPageUrl(): void
    {
        self::assertSame(['page_url' => 'https://shop.example.com/cart'], WidgetMetadata::sanitize(['page_url' => 'https://shop.example.com/cart'], null));
        self::assertNull(WidgetMetadata::sanitize(['page_url' => 'javascript:alert(1)'], null));
        self::assertNull(WidgetMetadata::sanitize(['page_url' => 'ftp://shop.example.com/file'], null));
    }

    public function testEmbeddedOnComesOnlyFromTheServer(): void
    {
        self::assertNull(WidgetMetadata::sanitize(['embedded_on' => 'https://spoofed.example'], null));
        self::assertSame(
            ['embedded_on' => 'https://shop.example.com'],
            WidgetMetadata::sanitize(['embedded_on' => 'https://spoofed.example'], 'https://shop.example.com'),
        );
        self::assertSame(['embedded_on' => 'https://shop.example.com'], WidgetMetadata::sanitize(null, 'https://shop.example.com'));
    }

    public function testNothingLeftIsNull(): void
    {
        self::assertNull(WidgetMetadata::sanitize(null, null));
        self::assertNull(WidgetMetadata::sanitize([], null));
        self::assertNull(WidgetMetadata::sanitize(['x' => ['y']], null));
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function shapes(): iterable
    {
        yield 'absent' => [null, true];
        yield 'object' => [['plan' => 'pro'], true];
        yield 'empty object' => [[], true];
        yield 'fifty keys' => [array_fill_keys(array_map(static fn (int $i): string => 'k'.$i, range(1, 50)), 1), true];
        yield 'fifty-one keys' => [array_fill_keys(array_map(static fn (int $i): string => 'k'.$i, range(1, 51)), 1), false];
        yield 'string' => ['plan=pro', false];
        yield 'number' => [5, false];
        yield 'list' => [['a', 'b'], false];
    }

    #[DataProvider('shapes')]
    public function testOnlyAnObjectOfAtMostFiftyKeysIsAccepted(mixed $metadata, bool $valid): void
    {
        self::assertSame($valid, WidgetMetadata::isValid($metadata));
    }
}
