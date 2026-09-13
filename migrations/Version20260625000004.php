<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260625000004 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_ticket_links table';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $links = $schema->createTable('escalated_ticket_links');
        $links->addColumn('id', 'integer', ['autoincrement' => true]);
        $links->addColumn('parent_ticket_id', 'integer');
        $links->addColumn('child_ticket_id', 'integer');
        $links->addColumn('link_type', 'string', ['length' => 32]);
        $links->addColumn('created_at', 'datetime_immutable');
        $links->setPrimaryKey(['id']);
        $links->addIndex(['parent_ticket_id'], 'idx_ticket_link_parent');
        $links->addIndex(['child_ticket_id'], 'idx_ticket_link_child');
        $links->addForeignKeyConstraint('escalated_tickets', ['parent_ticket_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_escalated_ticket_links_parent');
        $links->addForeignKeyConstraint('escalated_tickets', ['child_ticket_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_escalated_ticket_links_child');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_ticket_links');
    }
}
