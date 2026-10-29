<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

/**
 * The plugin contract's two tables: escalated_plugins (which plugins are
 * active) and escalated_plugin_store (what ctx.store and ctx.config keep).
 * The store's lookup index stays within MySQL's index length (191 + 100 + 191).
 */
final class Version20261029000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add plugins and plugin_store';
    }

    public function up(Schema $schema): void
    {
        $plugins = $schema->createTable($this->table('plugins'));
        $plugins->addColumn('id', 'integer', ['autoincrement' => true]);
        $plugins->addColumn('slug', 'string', ['length' => 255]);
        $plugins->addColumn('is_active', 'boolean', ['default' => false]);
        $plugins->addColumn('activated_at', 'datetime_immutable', ['notnull' => false]);
        $plugins->addColumn('deactivated_at', 'datetime_immutable', ['notnull' => false]);
        $plugins->addColumn('created_at', 'datetime_immutable');
        $plugins->addColumn('updated_at', 'datetime_immutable');
        $plugins->setPrimaryKey(['id']);
        $plugins->addUniqueIndex(['slug'], 'uniq_plugin_slug');

        $store = $schema->createTable($this->table('plugin_store'));
        $store->addColumn('id', 'integer', ['autoincrement' => true]);
        $store->addColumn('plugin', 'string', ['length' => 191]);
        $store->addColumn('collection', 'string', ['length' => 100]);
        // `key` is reserved on MySQL; backticks make DBAL quote it.
        $store->addColumn('`key`', 'string', ['length' => 191, 'notnull' => false]);
        $store->addColumn('data', 'json', ['notnull' => false]);
        $store->addColumn('created_at', 'datetime_immutable');
        $store->addColumn('updated_at', 'datetime_immutable');
        $store->setPrimaryKey(['id']);
        $store->addIndex(['plugin', 'collection', '`key`'], 'idx_plugin_store_lookup');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->table('plugin_store'));
        $schema->dropTable($this->table('plugins'));
    }
}
