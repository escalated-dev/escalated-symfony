<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Reference {@see AttachmentStorageInterface} that writes to the
 * local filesystem under a configured root. Files are prefixed with
 * a UTC timestamp (with microseconds) to avoid collisions between
 * uploads with the same original filename.
 *
 * Host apps with durable cloud storage needs should implement
 * {@see AttachmentStorageInterface} themselves and inject their
 * S3 / GCS / Azure adapter into {@see AttachmentDownloader} instead
 * of using this class.
 */
final class LocalFileAttachmentStorage implements AttachmentStorageInterface
{
    public function __construct(
        private readonly string $root,
    ) {
        if (trim($root) === '') {
            throw new \InvalidArgumentException('Local file storage root is required.');
        }
        if (! is_dir($root) && ! mkdir($root, 0o755, true) && ! is_dir($root)) {
            throw new \RuntimeException("Cannot create storage root: {$root}");
        }
    }

    public function name(): string
    {
        return 'local';
    }

    public function put(string $filename, string $content, string $contentType): string
    {
        // Microsecond-resolution prefix avoids collisions even under
        // rapid concurrent writes with the same original filename.
        [$usec, $sec] = explode(' ', microtime());
        $micros = (int) (((float) $usec) * 1_000_000);
        $prefix = gmdate('YmdHis', (int) $sec) . '-' . str_pad((string) $micros, 6, '0', STR_PAD_LEFT);
        $storedName = "{$prefix}-{$filename}";
        $fullPath = rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $storedName;

        if (false === file_put_contents($fullPath, $content)) {
            throw new \RuntimeException("Cannot write file: {$fullPath}");
        }
        return $fullPath;
    }
}
