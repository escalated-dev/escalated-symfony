<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260529000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_ticket_subjects table for polymorphic ticket subject links';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $subjects = $schema->createTable('escalated_ticket_subjects');
        $subjects->addColumn('id', 'integer', ['autoincrement' => true]);
        $subjects->addColumn('ticket_id', 'integer');
        $subjects->addColumn('subject_type', 'string', ['length' => 255]);
        $subjects->addColumn('subject_id', 'string', ['length' => 255]);
        $subjects->addColumn('role', 'string', ['length' => 255, 'notnull' => false]);
        $subjects->addColumn('position', 'integer', ['default' => 0]);
        $subjects->addColumn('created_at', 'datetime_immutable');
        $subjects->addColumn('updated_at', 'datetime_immutable');
        $subjects->setPrimaryKey(['id']);
        $subjects->addIndex(['subject_type', 'subject_id'], 'idx_ticket_subject_polymorphic');
        $subjects->addUniqueIndex(['ticket_id', 'subject_type', 'subject_id'], 'escalated_ticket_subject_unique');
        $subjects->addIndex(['ticket_id'], 'IDX_escalated_ticket_subjects_ticket');
        $subjects->addForeignKeyConstraint('escalated_tickets', ['ticket_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_escalated_ticket_subjects_ticket');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_ticket_subjects');
    }
}
