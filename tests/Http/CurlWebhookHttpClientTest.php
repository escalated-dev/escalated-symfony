<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Http;

use Escalated\Symfony\Http\CurlWebhookHttpClient;
use Escalated\Symfony\Http\WebhookTransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checking a webhook URL when it is saved is not enough: its host can resolve
 * somewhere else by the time an event is delivered, and URLs saved before the
 * check existed are still in the database. The transport must refuse an
 * internal target itself, before connecting.
 */
final class CurlWebhookHttpClientTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedTargets(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1:9/hook'];
        yield 'IPv6 loopback' => ['http://[::1]:9/hook'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'non-http scheme' => ['gopher://127.0.0.1:9/_INFO'];
    }

    #[DataProvider('refusedTargets')]
    public function testDeliveryToAnInternalTargetIsRefusedBeforeConnecting(string $url): void
    {
        $client = new CurlWebhookHttpClient();

        try {
            $client->post($url, '{}', ['Content-Type: application/json'], 2);
            self::fail("delivery to $url was attempted");
        } catch (WebhookTransportException $e) {
            self::assertStringContainsString('not allowed', $e->getMessage(), "delivery to $url failed for another reason: ".$e->getMessage());
        }
    }
}
