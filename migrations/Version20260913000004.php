<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

/**
 * Second half of aligning escalated_workflow_logs with the WorkflowLog entity:
 * carry the old `status` column over and drop it, and add the foreign keys the
 * entity's relations imply.
 *
 *  - status 'skipped' meant the conditions did not match;
 *  - executed rows ('success' / 'failure') only ever recorded created_at, so
 *    that is the best start and completion time available;
 *  - log rows for workflows or tickets that no longer exist are removed, since
 *    the foreign keys would reject them. Deleting a workflow or ticket now
 *    removes its log rows.
 */
final class Version20260913000004 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Move escalated_workflow_logs from status to conditions_matched/started_at/completed_at and add its foreign keys';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE '.$this->table('workflow_logs').' SET conditions_matched = ? WHERE status = ?',
            [false, 'skipped'],
            [Types::BOOLEAN, Types::STRING],
        );
        $this->addSql(
            'UPDATE '.$this->table('workflow_logs').' SET started_at = created_at, completed_at = created_at WHERE status <> ?',
            ['skipped'],
            [Types::STRING],
        );
        $this->addSql(
            'DELETE FROM '.$this->table('workflow_logs')
            .' WHERE workflow_id NOT IN (SELECT id FROM '.$this->table('workflows').')'
            .' OR ticket_id NOT IN (SELECT id FROM '.$this->table('tickets').')'
        );

        $logs = $schema->getTable($this->table('workflow_logs'));
        $logs->dropColumn('status');
        $logs->addForeignKeyConstraint($this->table('workflows'), ['workflow_id'], ['id'], ['onDelete' => 'CASCADE'], $this->generatedName('fk', 'workflow_logs', ['workflow_id']));
        $logs->addForeignKeyConstraint($this->table('tickets'), ['ticket_id'], ['id'], ['onDelete' => 'CASCADE'], $this->generatedName('fk', 'workflow_logs', ['ticket_id']));
    }

    public function down(Schema $schema): void
    {
        // Best effort: `status` comes back as 'success' for every row, since
        // success and failure are not recoverable from the new columns here.
        $logs = $schema->getTable($this->table('workflow_logs'));
        $logs->dropForeignKey($this->generatedName('fk', 'workflow_logs', ['ticket_id']));
        $logs->dropForeignKey($this->generatedName('fk', 'workflow_logs', ['workflow_id']));
        $logs->addColumn('status', 'string', ['length' => 32, 'default' => 'success']);
    }
}
