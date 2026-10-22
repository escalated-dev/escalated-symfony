<?php

declare(strict_types=1);

namespace Escalated\Symfony\Import;

/**
 * One batch from {@see \Escalated\Symfony\Contract\ImportAdapterInterface::extract()}.
 */
final class ExtractResult
{
    /**
     * @param list<array<string, mixed>> $records    normalized records
     * @param string|null                $cursor     next cursor; null when exhausted
     * @param int|null                   $totalCount estimated total, if the source knows it
     */
    public function __construct(
        public readonly array $records,
        public readonly ?string $cursor,
        public readonly ?int $totalCount = null,
    ) {
    }

    public function isExhausted(): bool
    {
        return null === $this->cursor;
    }
}
