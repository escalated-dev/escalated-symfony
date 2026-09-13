<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Http;

use Escalated\Symfony\Http\WebhookTransportException;
use Escalated\Symfony\Http\WebhookUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookUrlGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'ftp' => ['ftp://93.184.215.14/hook'];
        yield 'gopher' => ['gopher://93.184.215.14:70/'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'no host' => ['http:///hook'];
        yield 'IPv4 loopback' => ['http://127.0.0.1/hook'];
        yield 'loopback range' => ['http://127.1.2.3/hook'];
        yield '10/8' => ['http://10.1.2.3/hook'];
        yield '172.16/12' => ['http://172.16.0.1/hook'];
        yield '192.168/16' => ['http://192.168.0.1/hook'];
        yield 'link-local metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'unspecified' => ['http://0.0.0.0/hook'];
        yield 'IPv6 loopback' => ['http://[::1]/hook'];
        yield 'IPv6 link-local' => ['http://[fe80::1]/hook'];
        yield 'IPv6 unique local' => ['http://[fc00::1]/hook'];
        yield 'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/hook'];
    }

    #[DataProvider('refusedUrls')]
    public function testInternalOrNonHttpUrlsAreRefusedOnSave(string $url): void
    {
        self::assertNotNull($this->guard()->check($url), "$url was accepted");
    }

    #[DataProvider('refusedUrls')]
    public function testInternalOrNonHttpUrlsAreRefusedOnDelivery(string $url): void
    {
        $this->expectException(WebhookTransportException::class);
        $this->expectExceptionMessage('not allowed');

        $this->guard()->resolve($url);
    }

    public function testPublicAddressesAreAllowed(): void
    {
        $guard = $this->guard();

        self::assertNull($guard->check('https://93.184.215.14/hooks/escalated'));
        self::assertNull($guard->check('http://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]:8080/hook'));
        self::assertSame(
            ['host' => '93.184.215.14', 'port' => 443, 'address' => '93.184.215.14'],
            $guard->resolve('https://93.184.215.14/hooks/escalated'),
        );
    }

    public function testAHostnameIsJudgedByEveryAddressItResolvesTo(): void
    {
        $guard = $this->guard([
            'hooks.example.com' => ['93.184.215.14'],
            'rebinding.example.com' => ['93.184.215.14', '10.0.0.8'],
            'internal.example.com' => ['192.168.10.20'],
        ]);

        self::assertNull($guard->check('https://hooks.example.com/x'));
        self::assertSame(
            ['host' => 'hooks.example.com', 'port' => 8443, 'address' => '93.184.215.14'],
            $guard->resolve('https://hooks.example.com:8443/x'),
        );

        self::assertNotNull($guard->check('https://rebinding.example.com/x'));
        self::assertNotNull($guard->check('https://internal.example.com/x'));

        $this->expectException(WebhookTransportException::class);
        $guard->resolve('https://internal.example.com/x');
    }

    public function testAnUnresolvableHostCanBeSavedButNotDeliveredTo(): void
    {
        $guard = $this->guard([]);

        self::assertNull($guard->check('https://not-yet-live.example.com/x'));

        $this->expectException(WebhookTransportException::class);
        $this->expectExceptionMessage('could not be resolved');
        $guard->resolve('https://not-yet-live.example.com/x');
    }

    public function testPrivateNetworksCanBeAllowedButOnlyOverHttp(): void
    {
        $guard = new WebhookUrlGuard(true, static fn (string $host): array => []);

        self::assertNull($guard->check('http://10.0.0.5:8080/hook'));
        self::assertSame(
            ['host' => '127.0.0.1', 'port' => 80, 'address' => '127.0.0.1'],
            $guard->resolve('http://127.0.0.1/hook'),
        );
        self::assertNotNull($guard->check('gopher://10.0.0.5/'));
    }

    /**
     * @param array<string, list<string>> $dns
     */
    private function guard(array $dns = []): WebhookUrlGuard
    {
        return new WebhookUrlGuard(false, static fn (string $host): array => $dns[$host] ?? []);
    }
}
