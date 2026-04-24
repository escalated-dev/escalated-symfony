<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Minimal contract for writing attachment bytes to a backend.
 * Implementations can persist to local filesystem
 * ({@see LocalFileAttachmentStorage}), S3, GCS, Azure Blob, etc.
 */
interface AttachmentStorageInterface
{
    /**
     * Short identifier written to {@see \Escalated\Symfony\Entity\Attachment::getDisk()}
     * so callers can later dispatch read requests to the right backend.
     */
    public function name(): string;

    /**
     * Persist the content and return a storage-specific path or key.
     */
    public function put(string $filename, string $content, string $contentType): string;
}
