<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine\Migration;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Version\MigrationFactory;

/**
 * Hands the configured `table_prefix` to every bundle migration.
 */
final class TicketAccessMigrationFactory implements MigrationFactory
{
    public function __construct(private readonly MigrationFactory $inner, private readonly string $tablePrefix = 'escalated_')
    {
    }

    public function createVersion(string $migrationClassName): AbstractMigration
    {
        $migration = $this->inner->createVersion($migrationClassName);
        if ($migration instanceof PrefixedMigration) {
            $migration->configureTablePrefix($this->tablePrefix);
        }

        return $migration;
    }
}
