<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Reference {@see AttachmentHttpClientInterface} backed by cURL.
 * Ships as the default so host apps without an HTTP client of their
 * own can use {@see AttachmentDownloader} out of the box.
 *
 * Host apps already using {@code symfony/http-client}, Guzzle, etc.
 * should implement {@see AttachmentHttpClientInterface} with a thin
 * adapter and pass it to the downloader instead.
 */
final class CurlAttachmentHttpClient implements AttachmentHttpClientInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function get(string $url, array $headers = []): AttachmentHttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is not available.');
        }

        $ch = curl_init($url);
        if (false === $ch) {
            throw new \RuntimeException("Failed to initialize cURL for {$url}");
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }

        $collectedHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_HEADERFUNCTION => function ($_handle, $rawHeader) use (&$collectedHeaders) {
                $len = strlen($rawHeader);
                $parts = explode(':', $rawHeader, 2);
                if (2 === count($parts)) {
                    $collectedHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $len;
            },
        ]);

        $body = curl_exec($ch);
        if (false === $body) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("HTTP request failed for {$url}: {$err}");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new AttachmentHttpResponse((int) $status, (string) $body, $collectedHeaders);
    }
}
