<?php

declare(strict_types=1);

namespace Escalated\Symfony\Http;

/**
 * Minimal HTTP transport used by the webhook dispatcher.
 *
 * Abstracted behind an interface so deliveries can be exercised in tests
 * without a network round-trip. The default implementation uses native
 * PHP curl because symfony/http-client is not a dependency of this bundle.
 */
interface WebhookHttpClientInterface
{
    /**
     * POST a raw body to $url and return the response.
     *
     * @param list<string> $headers Raw header lines, e.g. "Content-Type: application/json"
     *
     * @return array{status: int, body: string}
     *
     * @throws WebhookTransportException on a transport-level failure (DNS, timeout, connection refused)
     */
    public function post(string $url, string $body, array $headers, int $timeout): array;
}
