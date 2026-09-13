<?php

declare(strict_types=1);

namespace Escalated\Symfony\Http;

/**
 * Native-curl implementation of {@see WebhookHttpClientInterface}.
 *
 * symfony/http-client is intentionally not a dependency of this bundle, so
 * webhook delivery is performed with the ext-curl functions that ship with
 * every supported PHP build.
 *
 * Every delivery goes through {@see WebhookUrlGuard}: only http and https,
 * never a private or reserved address, no redirects. The connection is pinned
 * to the address the guard approved, so the host cannot resolve somewhere
 * else between the check and the request.
 */
final class CurlWebhookHttpClient implements WebhookHttpClientInterface
{
    public function __construct(
        private readonly WebhookUrlGuard $guard = new WebhookUrlGuard(),
    ) {
    }

    public function post(string $url, string $body, array $headers, int $timeout): array
    {
        $target = $this->guard->resolve($url);

        $handle = curl_init();
        if (false === $handle) {
            throw new WebhookTransportException('Unable to initialise curl handle.');
        }

        $pinnedAddress = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];

        curl_setopt_array($handle, [
            \CURLOPT_URL => $url,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => $timeout,
            \CURLOPT_CONNECTTIMEOUT => $timeout,
            \CURLOPT_FOLLOWLOCATION => false,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTP | \CURLPROTO_HTTPS,
            \CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $target['host'], $target['port'], $pinnedAddress)],
        ]);

        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (0 !== $errno || false === $response) {
            throw new WebhookTransportException('' !== $error ? $error : 'curl error '.$errno);
        }

        return ['status' => $status, 'body' => (string) $response];
    }
}
