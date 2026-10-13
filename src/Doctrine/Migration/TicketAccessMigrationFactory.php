<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine\Migration;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Version\MigrationFactory;

final class TicketAccessMigrationFactory implements MigrationFactory
{
    public function __construct(private readonly MigrationFactory $inner, private readonly string $tablePrefix = 'escalated_')
    {
    }

    public function createVersion(string $migrationClassName): AbstractMigration
    {
        $migration = $this->inner->createVersion($migrationClassName);
        if ($migration instanceof \Escalated\Symfony\Migrations\Version20261013000001) {
            $migration->configureTicketAccessPrefix($this->tablePrefix);
        }

        return $migration;
    }
}
