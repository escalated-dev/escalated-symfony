<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

final class Version20261021000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add verified guest access: email verification codes and expiring guest grants';
    }

    public function up(Schema $schema): void
    {
        $tickets = $schema->getTable($this->table('tickets'));
        $tickets->addColumn('guest_verified_email', 'string', ['length' => 255, 'notnull' => false]);
        $tickets->addColumn('guest_email_verified_at', 'datetime_immutable', ['notnull' => false]);
        $tickets->addColumn('guest_access_hash', 'string', ['length' => 64, 'notnull' => false]);
        $tickets->addColumn('guest_access_expires_at', 'datetime_immutable', ['notnull' => false]);

        $verifications = $schema->createTable($this->table('guest_verifications'));
        $verifications->addColumn('id', 'string', ['length' => 36]);
        $verifications->addColumn('email_digest', 'string', ['length' => 64]);
        $verifications->addColumn('purpose', 'string', ['length' => 16]);
        $verifications->addColumn('code_hash', 'string', ['length' => 64]);
        $verifications->addColumn('attempts', 'integer', ['default' => 0]);
        $verifications->addColumn('expires_at', 'datetime_immutable');
        $verifications->addColumn('used_at', 'datetime_immutable', ['notnull' => false]);
        $verifications->addColumn('created_at', 'datetime_immutable');
        $verifications->setPrimaryKey(['id']);
        $verifications->addIndex(['expires_at'], 'idx_guest_verification_expires');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->table('guest_verifications'));
        $tickets = $schema->getTable($this->table('tickets'));
        foreach (['guest_verified_email', 'guest_email_verified_at', 'guest_access_hash', 'guest_access_expires_at'] as $column) {
            $tickets->dropColumn($column);
        }
    }
}
