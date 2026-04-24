<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Provider-hosted attachment that the host app must download
 * out-of-band (Mailgun hosts large attachments behind a URL
 * instead of inlining them in the webhook payload).
 */
final class PendingAttachment
{
    public function __construct(
        public readonly string $name,
        public readonly string $contentType,
        public readonly ?int $sizeBytes,
        public readonly string $downloadUrl,
    ) {
    }
}
