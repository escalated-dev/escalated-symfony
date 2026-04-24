<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Runtime configuration for {@see AttachmentDownloader}.
 */
final class AttachmentDownloaderOptions
{
    public function __construct(
        /**
         * Reject attachments larger than this size. Zero disables
         * the check.
         */
        public readonly int $maxBytes = 0,

        /**
         * Optional HTTP basic auth credentials attached to every
         * download request. Typical Mailgun use:
         * {@code new BasicAuth('api', $mailgunApiKey)}.
         */
        public readonly ?BasicAuth $basicAuth = null,
    ) {
    }
}

final class BasicAuth
{
    public function __construct(
        public readonly string $username,
        public readonly string $password,
    ) {
    }
}
