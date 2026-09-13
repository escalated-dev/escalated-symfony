<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260625000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_escalation_rules table';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $rules = $schema->createTable('escalated_escalation_rules');
        $rules->addColumn('id', 'integer', ['autoincrement' => true]);
        $rules->addColumn('name', 'string', ['length' => 255]);
        $rules->addColumn('description', 'text', ['notnull' => false]);
        $rules->addColumn('trigger_type', 'string', ['length' => 255, 'notnull' => false]);
        $rules->addColumn('conditions', 'json');
        $rules->addColumn('actions', 'json');
        $rules->addColumn('sort_order', 'integer');
        $rules->addColumn('is_active', 'boolean');
        $rules->addColumn('created_at', 'datetime_immutable');
        $rules->addColumn('updated_at', 'datetime_immutable');
        $rules->setPrimaryKey(['id']);
        $rules->addIndex(['is_active'], 'IDX_escalated_escalation_rules_active');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_escalation_rules');
    }
}
