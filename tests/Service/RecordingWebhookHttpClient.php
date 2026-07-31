<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Http\WebhookHttpClientInterface;

/**
 * Test double for the webhook HTTP transport: records every request and
 * replays a caller-supplied handler so tests can assert on the outgoing
 * request and drive success / failure / exception paths without a network.
 */
final class RecordingWebhookHttpClient implements WebhookHttpClientInterface
{
    /** @var list<array{url: string, body: string, headers: list<string>, timeout: int}> */
    public array $calls = [];

    /** @var \Closure(int): array{status: int, body: string} */
    private \Closure $handler;

    /**
     * @param callable(int): array{status: int, body: string} $handler receives the 1-based call count
     */
    public function __construct(callable $handler)
    {
        $this->handler = \Closure::fromCallable($handler);
    }

    public static function alwaysReturns(int $status, string $body = ''): self
    {
        return new self(static fn (): array => ['status' => $status, 'body' => $body]);
    }

    public function post(string $url, string $body, array $headers, int $timeout): array
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers, 'timeout' => $timeout];

        return ($this->handler)(\count($this->calls));
    }

    public function lastHeaders(): array
    {
        return $this->calls[array_key_last($this->calls)]['headers'] ?? [];
    }

    public function lastBody(): string
    {
        return $this->calls[array_key_last($this->calls)]['body'] ?? '';
    }
}
