<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * First half of aligning escalated_workflow_logs with the WorkflowLog entity
 * (and the NestJS reference): add conditions_matched, started_at and
 * completed_at. The next migration fills them from the old `status` column
 * and drops it; the two are separate because a migration's own SQL runs
 * before its schema changes.
 */
final class Version20260913000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add conditions_matched, started_at and completed_at to escalated_workflow_logs';
    }

    public function up(Schema $schema): void
    {
        $logs = $schema->getTable('escalated_workflow_logs');
        $logs->addColumn('conditions_matched', 'boolean', ['default' => true]);
        $logs->addColumn('started_at', 'datetime_immutable', ['notnull' => false]);
        $logs->addColumn('completed_at', 'datetime_immutable', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $logs = $schema->getTable('escalated_workflow_logs');
        $logs->dropColumn('completed_at');
        $logs->dropColumn('started_at');
        $logs->dropColumn('conditions_matched');
    }
}
