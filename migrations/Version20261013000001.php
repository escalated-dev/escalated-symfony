<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Escalated\Symfony\Doctrine\UserIdType;

final class Version20261013000001 extends AbstractMigration
{
    private string $prefix = 'escalated_';

    public function configureTicketAccessPrefix(string $prefix): void
    {
        $this->prefix = $prefix;
    }

    public function getDescription(): string
    {
        return 'Add nullable ticket accounts, SLA targets, and department agent memberships';
    }

    public function up(Schema $schema): void
    {
        $tickets = $schema->getTable($this->prefix.'tickets');
        $platform = $this->connection->getDatabasePlatform();
        $collation = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform ? 'C' : ($platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform ? 'utf8mb4_bin' : 'BINARY');
        foreach (['account_type', 'account_id'] as $column) {
            $tickets->addColumn($column, 'string', ['length' => 191, 'notnull' => false, 'platformOptions' => ['collation' => $collation]]);
        }
        $tickets->addIndex(['account_type', 'account_id'], 'idx_ticket_account');
        $policies = $schema->getTable($this->prefix.'sla_policies');
        $policies->addColumn('department_id', 'integer', ['notnull' => false]);
        $policies->addColumn('ticket_type', 'string', ['length' => 32, 'notnull' => false]);
        $policies->addIndex(['department_id', 'ticket_type'], 'idx_sla_target');
        $policies->addForeignKeyConstraint($this->prefix.'departments', ['department_id'], ['id'], ['onDelete' => 'RESTRICT']);
        $agents = $schema->createTable($this->prefix.'department_agent');
        $agents->addColumn('department_id', 'integer');
        $agents->addColumn('agent_id', UserIdType::NAME);
        $agents->setPrimaryKey(['department_id', 'agent_id']);
        $agents->addForeignKeyConstraint($this->prefix.'departments', ['department_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->prefix.'department_agent');
        $tickets = $schema->getTable($this->prefix.'tickets');
        $tickets->dropIndex('idx_ticket_account');
        $tickets->dropColumn('account_type');
        $tickets->dropColumn('account_id');
        $policies = $schema->getTable($this->prefix.'sla_policies');
        foreach ($policies->getForeignKeys() as $fk) {
            if (in_array('department_id', $fk->getLocalColumns(), true)) {
                $policies->dropForeignKey($fk->getName());
            }
        }
        foreach ($policies->getIndexes() as $index) {
            if (in_array('department_id', $index->getColumns(), true)) {
                $policies->dropIndex($index->getName());
            }
        }
        $policies->dropColumn('department_id');
        $policies->dropColumn('ticket_type');
    }
}
