<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Tiny HTTP client contract scoped to what
 * {@see AttachmentDownloader} needs: a single GET method returning
 * status + body + headers. Intentionally decoupled from
 * {@code symfony/http-client} so the bundle doesn't force an extra
 * dependency on host apps that already have their own HTTP client.
 *
 * Reference implementation: {@see CurlAttachmentHttpClient}. Host
 * apps wiring symfony/http-client, Guzzle, etc. can implement this
 * interface with a thin adapter.
 */
interface AttachmentHttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function get(string $url, array $headers = []): AttachmentHttpResponse;
}
