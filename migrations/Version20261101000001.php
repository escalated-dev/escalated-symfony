<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

/**
 * Single sign-on (ADR 2026-10-29-configurable-access-features): the
 * single-use message IDs SSO needs, the AuthnRequest IDs this install issued
 * and the SAML assertion IDs and JWT `jti`s it accepted (replay protection).
 * `expires_at` is Unix seconds; expired rows are pruned on write.
 */
final class Version20261101000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add sso_message_ids';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable($this->table('sso_message_ids'));
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('kind', 'string', ['length' => 32]);
        $table->addColumn('message_id', 'string', ['length' => 191]);
        $table->addColumn('expires_at', 'bigint');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['kind', 'message_id'], $this->generatedName('uniq', 'sso_message_ids', ['kind', 'message_id']));
        $table->addIndex(['expires_at'], $this->generatedName('idx', 'sso_message_ids', ['expires_at']));
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->table('sso_message_ids'));
    }
}
