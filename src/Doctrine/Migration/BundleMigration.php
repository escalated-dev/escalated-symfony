<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Base class for the bundle migrations that used to ship in the global
 * `DoctrineMigrations` namespace.
 *
 * All of the bundle's migrations now live in `Escalated\Symfony\Migrations`,
 * registered by the bundle itself. Doctrine Migrations records executed
 * migrations by class name, so an install that ran one of these as
 * `DoctrineMigrations\VersionX` would otherwise see
 * `Escalated\Symfony\Migrations\VersionX` as new and run it again.
 *
 * When the old name is recorded, preUp() marks the migration as already
 * executed and the subclass's up() returns without changing anything. Doctrine
 * then records the new name in the same transaction. The old row is left in
 * place, so nothing that relied on it -- a copy of the migration in the host's
 * own migrations directory, say -- starts looking unexecuted.
 *
 * This reads Doctrine's default metadata table (doctrine_migration_versions,
 * column `version`). A host that renamed it adds the new versions by hand; see
 * the README's upgrade notes.
 */
abstract class BundleMigration extends AbstractMigration
{
    private const LEGACY_NAMESPACE = 'DoctrineMigrations';

    private const DEFAULT_METADATA_TABLE = 'doctrine_migration_versions';

    private bool $executedUnderLegacyName = false;

    public function preUp(Schema $schema): void
    {
        $this->executedUnderLegacyName = $this->legacyVersionIsRecorded();

        if ($this->executedUnderLegacyName) {
            $this->write(sprintf(
                'Already executed as %s; recording it as %s without running it again.',
                $this->legacyVersion(),
                static::class,
            ));
        }
    }

    /**
     * True when this migration already ran under its old class name. up() must
     * return without touching the schema.
     */
    protected function executedUnderLegacyName(): bool
    {
        return $this->executedUnderLegacyName;
    }

    private function legacyVersion(): string
    {
        $class = static::class;

        return self::LEGACY_NAMESPACE.'\\'.substr($class, (int) strrpos($class, '\\') + 1);
    }

    private function legacyVersionIsRecorded(): bool
    {
        if (!$this->connection->createSchemaManager()->tablesExist([self::DEFAULT_METADATA_TABLE])) {
            return false;
        }

        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM '.self::DEFAULT_METADATA_TABLE.' WHERE version = ?',
            [$this->legacyVersion()],
        );
    }
}
