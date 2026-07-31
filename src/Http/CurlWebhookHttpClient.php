<?php

declare(strict_types=1);

namespace Escalated\Symfony\Http;

/**
 * Native-curl implementation of {@see WebhookHttpClientInterface}.
 *
 * symfony/http-client is intentionally not a dependency of this bundle, so
 * webhook delivery is performed with the ext-curl functions that ship with
 * every supported PHP build.
 */
final class CurlWebhookHttpClient implements WebhookHttpClientInterface
{
    public function post(string $url, string $body, array $headers, int $timeout): array
    {
        $handle = curl_init();
        if (false === $handle) {
            throw new WebhookTransportException('Unable to initialise curl handle.');
        }

        curl_setopt_array($handle, [
            \CURLOPT_URL => $url,
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => $timeout,
            \CURLOPT_CONNECTTIMEOUT => $timeout,
            \CURLOPT_FOLLOWLOCATION => false,
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
