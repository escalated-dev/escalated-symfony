<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine\Migration;

use Doctrine\Migrations\AbstractMigration;

/**
 * Base for the bundle's migrations: table names go through table(), so the
 * tables they create and alter carry the configured `table_prefix`, the same
 * one {@see \Escalated\Symfony\Doctrine\TicketAccessMetadata} gives the
 * entities. {@see TicketAccessMigrationFactory} injects the prefix.
 */
abstract class PrefixedMigration extends AbstractMigration
{
    private string $tablePrefix = 'escalated_';

    public function configureTablePrefix(string $prefix): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid Escalated table prefix.');
        }

        $this->tablePrefix = $prefix;
    }

    /**
     * The real name of a bundle table, given without its prefix
     * (`table('tickets')` is `escalated_tickets` by default).
     */
    protected function table(string $name): string
    {
        return $this->tablePrefix.$name;
    }

    /**
     * The index or foreign key name Doctrine generates for `$columns` of a
     * bundle table (`fk`, `idx` or `uniq`), the same one the ORM mapping
     * expects. It hashes the real table name, so it changes with the prefix
     * and cannot be hard-coded.
     *
     * @param list<string> $columns
     */
    protected function generatedName(string $type, string $table, array $columns): string
    {
        $hash = implode('', array_map(
            static fn (string $part): string => dechex(crc32($part)),
            [$this->table($table), ...$columns],
        ));

        return strtoupper(substr($type.'_'.$hash, 0, 30));
    }
}
