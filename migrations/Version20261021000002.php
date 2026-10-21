<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

final class Version20261021000002 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add tickets.merged_into_id for ticket merge';
    }

    public function up(Schema $schema): void
    {
        $tickets = $schema->getTable($this->table('tickets'));
        $tickets->addColumn('merged_into_id', 'integer', ['notnull' => false]);
        $tickets->addIndex(['merged_into_id'], 'idx_ticket_merged_into');
    }

    public function down(Schema $schema): void
    {
        $tickets = $schema->getTable($this->table('tickets'));
        $tickets->dropIndex('idx_ticket_merged_into');
        $tickets->dropColumn('merged_into_id');
    }
}
