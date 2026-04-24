<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Thrown by {@see AttachmentDownloader::download()} when a downloaded
 * attachment exceeds {@see AttachmentDownloaderOptions::$maxBytes}.
 * The partial body is not persisted.
 */
final class AttachmentTooLargeException extends \RuntimeException
{
    public function __construct(
        public readonly string $attachmentName,
        public readonly int $actualBytes,
        public readonly int $maxBytes,
    ) {
        parent::__construct(sprintf(
            "Attachment '%s' is %d bytes, exceeds limit %d.",
            $attachmentName,
            $actualBytes,
            $maxBytes
        ));
    }
}
