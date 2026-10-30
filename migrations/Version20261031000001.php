<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

/**
 * Two-factor authentication (ADR 2026-10-29-configurable-access-features):
 *
 * - `last_used_step`, the TOTP time step of the last code accepted for a
 *   user, so the same code (or an older one) cannot be used again inside its
 *   validity window;
 * - `recovery_codes` becomes TEXT: the codes are now stored sealed (encrypted),
 *   like the secret, rather than as plain JSON.
 *
 * Existing rows are removed. They were written by the old TwoFactorService,
 * which stored the secret and the recovery codes in plain text and checked
 * codes with a scheme that was not RFC 6238, so no authenticator app could
 * ever have produced a code it accepted, and no route used them.
 */
final class Version20261031000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add two_factors.last_used_step, store recovery codes sealed';
    }

    public function preUp(Schema $schema): void
    {
        $this->connection->executeStatement('DELETE FROM '.$this->table('two_factors'));
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable($this->table('two_factors'));
        $table->addColumn('last_used_step', 'bigint', ['notnull' => false]);
        $table->modifyColumn('recovery_codes', ['type' => Type::getType(Types::TEXT)]);
    }

    public function preDown(Schema $schema): void
    {
        $this->connection->executeStatement('DELETE FROM '.$this->table('two_factors'));
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable($this->table('two_factors'));
        $table->modifyColumn('recovery_codes', ['type' => Type::getType(Types::JSON)]);
        $table->dropColumn('last_used_step');
    }
}
