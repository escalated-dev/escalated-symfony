<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

/**
 * The Automation, Macro and SavedView entities shipped without a migration, so
 * a migrated install had no table behind them. Hosts that created the tables
 * with doctrine:schema:update already have them; those are left alone.
 */
final class Version20260913000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_automations, escalated_macros and escalated_saved_views where missing';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable($this->table('automations'))) {
            $automations = $schema->createTable($this->table('automations'));
            $automations->addColumn('id', 'integer', ['autoincrement' => true]);
            $automations->addColumn('name', 'string', ['length' => 255]);
            $automations->addColumn('description', 'text', ['notnull' => false]);
            $automations->addColumn('conditions', 'json');
            $automations->addColumn('actions', 'json');
            $automations->addColumn('active', 'boolean');
            $automations->addColumn('position', 'integer');
            $automations->addColumn('last_run_at', 'datetime_immutable', ['notnull' => false]);
            $automations->addColumn('created_at', 'datetime_immutable');
            $automations->addColumn('updated_at', 'datetime_immutable');
            $automations->setPrimaryKey(['id']);
        }

        if (!$schema->hasTable($this->table('macros'))) {
            $macros = $schema->createTable($this->table('macros'));
            $macros->addColumn('id', 'integer', ['autoincrement' => true]);
            $macros->addColumn('name', 'string', ['length' => 255]);
            $macros->addColumn('description', 'text', ['notnull' => false]);
            $macros->addColumn('actions', 'json');
            $macros->addColumn('is_shared', 'boolean');
            $macros->addColumn('created_by', 'integer', ['notnull' => false]);
            $macros->addColumn('created_at', 'datetime_immutable');
            $macros->addColumn('updated_at', 'datetime_immutable');
            $macros->setPrimaryKey(['id']);
        }

        if (!$schema->hasTable($this->table('saved_views'))) {
            $views = $schema->createTable($this->table('saved_views'));
            $views->addColumn('id', 'integer', ['autoincrement' => true]);
            $views->addColumn('name', 'string', ['length' => 255]);
            $views->addColumn('user_id', 'integer');
            $views->addColumn('filters', 'json');
            $views->addColumn('sort_by', 'string', ['length' => 16, 'notnull' => false]);
            $views->addColumn('sort_dir', 'string', ['length' => 4, 'notnull' => false]);
            $views->addColumn('is_shared', 'boolean');
            $views->addColumn('is_default', 'boolean');
            $views->addColumn('position', 'integer');
            $views->addColumn('color', 'string', ['length' => 7, 'notnull' => false]);
            $views->addColumn('icon', 'string', ['length' => 32, 'notnull' => false]);
            $views->addColumn('created_at', 'datetime_immutable');
            $views->addColumn('updated_at', 'datetime_immutable');
            $views->setPrimaryKey(['id']);
            $views->addIndex(['user_id'], 'idx_saved_view_user');
            $views->addIndex(['is_shared'], 'idx_saved_view_shared');
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->table('saved_views'));
        $schema->dropTable($this->table('macros'));
        $schema->dropTable($this->table('automations'));
    }
}
