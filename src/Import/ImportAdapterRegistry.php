<?php

declare(strict_types=1);

namespace Escalated\Symfony\Import;

use Escalated\Symfony\Contract\ImportAdapterInterface;

/**
 * The import sources the host and its plugin bundles registered: every
 * service tagged `escalated.import_adapter` (autoconfigured for
 * {@see ImportAdapterInterface}). Laravel's `import.adapters` filter.
 */
class ImportAdapterRegistry
{
    /** @var list<ImportAdapterInterface> */
    private array $adapters = [];

    /** @param iterable<ImportAdapterInterface> $adapters */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->adapters[] = $adapter;
        }
    }

    /** @return list<ImportAdapterInterface> */
    public function all(): array
    {
        return $this->adapters;
    }

    public function find(string $platform): ?ImportAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->name() === $platform) {
                return $adapter;
            }
        }

        return null;
    }
}
