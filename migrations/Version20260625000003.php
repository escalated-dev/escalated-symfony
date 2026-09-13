<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260625000003 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_agent_capacity table';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $capacity = $schema->createTable('escalated_agent_capacity');
        $capacity->addColumn('id', 'integer', ['autoincrement' => true]);
        $capacity->addColumn('user_id', 'string', ['length' => 255]);
        $capacity->addColumn('channel', 'string', ['length' => 64]);
        $capacity->addColumn('max_concurrent', 'integer', ['default' => 10]);
        $capacity->addColumn('current_count', 'integer', ['default' => 0]);
        $capacity->addColumn('created_at', 'datetime_immutable');
        $capacity->addColumn('updated_at', 'datetime_immutable');
        $capacity->setPrimaryKey(['id']);
        $capacity->addUniqueIndex(['user_id', 'channel'], 'escalated_agent_capacity_user_channel_unique');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_agent_capacity');
    }
}
