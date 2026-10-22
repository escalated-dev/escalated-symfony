<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

/**
 * Custom ticket statuses: the ticket_statuses table and a nullable
 * tickets.ticket_status_id. Existing tickets keep their built-in status and
 * get NULL; nothing is backfilled. Version20261024000002 seeds the system rows.
 */
final class Version20261024000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add custom ticket statuses and tickets.ticket_status_id';
    }

    public function up(Schema $schema): void
    {
        $statuses = $schema->createTable($this->table('ticket_statuses'));
        $statuses->addColumn('id', 'integer', ['autoincrement' => true]);
        $statuses->addColumn('label', 'string', ['length' => 255]);
        $statuses->addColumn('slug', 'string', ['length' => 191]);
        $statuses->addColumn('category', 'string', ['length' => 16]);
        $statuses->addColumn('color', 'string', ['length' => 20, 'default' => '#6b7280']);
        $statuses->addColumn('description', 'text', ['notnull' => false]);
        $statuses->addColumn('position', 'integer', ['default' => 0]);
        $statuses->addColumn('is_default', 'boolean', ['default' => false]);
        $statuses->addColumn('is_system', 'boolean', ['default' => false]);
        $statuses->addColumn('created_at', 'datetime_immutable');
        $statuses->addColumn('updated_at', 'datetime_immutable');
        $statuses->setPrimaryKey(['id']);
        $statuses->addUniqueIndex(['slug'], 'uniq_ticket_status_slug');

        $tickets = $schema->getTable($this->table('tickets'));
        $tickets->addColumn('ticket_status_id', 'integer', ['notnull' => false]);
        $tickets->addIndex(['ticket_status_id'], 'idx_ticket_custom_status');
        $tickets->addForeignKeyConstraint($this->table('ticket_statuses'), ['ticket_status_id'], ['id'], ['onDelete' => 'SET NULL'], $this->generatedName('fk', 'tickets', ['ticket_status_id']));
    }

    public function down(Schema $schema): void
    {
        $tickets = $schema->getTable($this->table('tickets'));
        $tickets->dropForeignKey($this->generatedName('fk', 'tickets', ['ticket_status_id']));
        $tickets->dropIndex('idx_ticket_custom_status');
        $tickets->dropColumn('ticket_status_id');
        $schema->dropTable($this->table('ticket_statuses'));
    }
}
