<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

use Escalated\Symfony\Entity\Attachment;

/**
 * Per-attachment outcome returned by
 * {@see AttachmentDownloader::downloadAll()}.
 * {@see $persisted} is non-null on success; {@see $error} is non-null
 * on failure.
 */
final class AttachmentDownloadResult
{
    public function __construct(
        public readonly PendingAttachment $pending,
        public readonly ?Attachment $persisted,
        public readonly ?\Throwable $error,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->persisted !== null;
    }
}
