<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Widget;

use Escalated\Symfony\Service\SettingsService;
use Escalated\Symfony\Widget\WidgetOrigins;
use Escalated\Symfony\Widget\WidgetSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Which other sites may embed the widget: the WidgetSettings allowedOrigins
 * list plus the `widget_allowed_origins` admin setting. Mirrors
 * escalated-laravel's tests/Unit/WidgetOriginsTest.php.
 */
final class WidgetOriginsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function entries(): iterable
    {
        yield 'mixed case' => ['https://Shop.Example.com', 'https://shop.example.com'];
        yield 'trailing slash' => ['https://shop.example.com/', 'https://shop.example.com'];
        yield 'default port' => ['https://shop.example.com:443', 'https://shop.example.com'];
        yield 'other port' => ['http://localhost:5173', 'http://localhost:5173'];
        yield 'wildcard' => ['https://*.example.com', 'https://*.example.com'];
        yield 'wildcard with port' => ['https://*.example.com:8443', 'https://*.example.com:8443'];
        yield 'path' => ['https://shop.example.com/checkout', null];
        yield 'query' => ['https://shop.example.com?x=1', null];
        yield 'no scheme' => ['shop.example.com', null];
        yield 'bare star' => ['*', null];
        yield 'scheme and star' => ['https://*', null];
        yield 'inner wildcard' => ['https://a.*.example.com', null];
        yield 'ftp' => ['ftp://shop.example.com', null];
        yield 'userinfo' => ['https://user@shop.example.com', null];
    }

    #[DataProvider('entries')]
    public function testNormalisesEntriesAndRejectsAnythingThatIsNotABareOrigin(string $entry, ?string $expected): void
    {
        self::assertSame($expected, WidgetOrigins::normalizePattern($entry));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function origins(): iterable
    {
        yield 'exact' => ['https://shop.example.com', true];
        yield 'case and default port' => ['https://SHOP.example.com:443', true];
        yield 'other scheme' => ['http://shop.example.com', false];
        yield 'other port' => ['https://shop.example.com:8443', false];
        yield 'subdomain' => ['https://help.brand.test', true];
        yield 'deep subdomain' => ['https://a.b.brand.test', true];
        yield 'apex' => ['https://brand.test', false];
        yield 'suffix lookalike' => ['https://notbrand.test', false];
        yield 'appended domain' => ['https://help.brand.test.evil.com', false];
        yield 'localhost with port' => ['http://localhost:5173', true];
        yield 'localhost without port' => ['http://localhost', false];
        yield 'null origin' => ['null', false];
    }

    #[DataProvider('origins')]
    public function testMatchesExactOriginsPortsAndSubdomainWildcards(string $origin, bool $allowed): void
    {
        $origins = $this->widgetOrigins(['https://shop.example.com', 'https://*.brand.test', 'http://localhost:5173']);

        self::assertSame($allowed, $origins->allows($origin));
    }

    public function testAnEmptyListAllowsNoSite(): void
    {
        $origins = $this->widgetOrigins([]);

        self::assertFalse($origins->allows('https://shop.example.com'));
        self::assertSame([], $origins->configured());
    }

    public function testMergesTheConfigListWithTheAdminSetting(): void
    {
        $origins = $this->widgetOrigins(['https://shop.example.com'], "https://blog.example.com, https://bad.example.com/path\n");

        self::assertSame(['https://shop.example.com', 'https://blog.example.com'], $origins->configured());
        self::assertTrue($origins->allows('https://blog.example.com'));
        self::assertFalse($origins->allows('https://bad.example.com'));
    }

    public function testListsInvalidAdminEntries(): void
    {
        self::assertSame(
            ['https://shop.example.com/checkout', '*'],
            WidgetOrigins::invalidEntries("https://ok.example.com\nhttps://shop.example.com/checkout\n*, https://*.brand.test"),
        );
        self::assertSame([], WidgetOrigins::invalidEntries(''));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function requestOrigins(): iterable
    {
        yield 'no Origin' => [null, null];
        yield 'opaque origin' => ['null', null];
        yield 'same origin' => ['https://help.example.com', null];
        yield 'same origin with default port' => ['https://help.example.com:443', null];
        yield 'other site' => ['https://shop.example.com', 'https://shop.example.com'];
        yield 'other scheme' => ['http://help.example.com', 'http://help.example.com'];
    }

    #[DataProvider('requestOrigins')]
    public function testOnlyReportsAnOriginThatNamesAnotherSite(?string $origin, ?string $expected): void
    {
        $request = Request::create('https://help.example.com/support/widget/api/config', 'GET', server: array_filter(['HTTP_ORIGIN' => $origin]));

        self::assertSame($expected, WidgetOrigins::crossOrigin($request));
    }

    /**
     * @param list<string> $configured
     */
    private function widgetOrigins(array $configured, ?string $setting = null): WidgetOrigins
    {
        $settings = $this->createMock(SettingsService::class);
        $settings->method('get')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => WidgetOrigins::SETTING === $key ? ($setting ?? $default) : $default,
        );

        return new WidgetOrigins(new WidgetSettings(allowedOrigins: $configured), $settings);
    }
}
