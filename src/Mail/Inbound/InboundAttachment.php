<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Single attachment on an inbound email. Providers either inline the
 * content (small attachments) or supply a URL to download it from
 * (larger provider-hosted attachments).
 */
final class InboundAttachment
{
    public function __construct(
        public readonly string $name,
        public readonly string $contentType,
        public readonly ?int $sizeBytes = null,
        public readonly ?string $content = null,
        public readonly ?string $downloadUrl = null,
    ) {
    }
}
