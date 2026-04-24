<?php

declare(strict_types=1);

namespace Escalated\Symfony\Mail\Inbound;

/**
 * Immutable response record returned by
 * {@see AttachmentHttpClientInterface::get()}.
 */
final class AttachmentHttpResponse
{
    /**
     * @param array<string, string> $headers lower-cased header names
     *     → first value.
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function headerValue(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
