<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260802000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_api_tokens table';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $tokens = $schema->createTable('escalated_api_tokens');
        $tokens->addColumn('id', 'integer', ['autoincrement' => true]);
        $tokens->addColumn('user_id', 'string', ['length' => 255]);
        $tokens->addColumn('name', 'string', ['length' => 255]);
        $tokens->addColumn('token', 'string', ['length' => 64]);
        $tokens->addColumn('abilities', 'json', ['notnull' => false]);
        $tokens->addColumn('last_used_at', 'datetime_immutable', ['notnull' => false]);
        $tokens->addColumn('last_used_ip', 'string', ['length' => 45, 'notnull' => false]);
        $tokens->addColumn('expires_at', 'datetime_immutable', ['notnull' => false]);
        $tokens->addColumn('created_at', 'datetime_immutable');
        $tokens->addColumn('updated_at', 'datetime_immutable');
        $tokens->setPrimaryKey(['id']);
        $tokens->addUniqueIndex(['token'], 'uniq_escalated_api_token');
        $tokens->addIndex(['user_id'], 'idx_api_token_user');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_api_tokens');
    }
}
