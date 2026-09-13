<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260625000002 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_satisfaction_ratings table';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $ratings = $schema->createTable('escalated_satisfaction_ratings');
        $ratings->addColumn('id', 'integer', ['autoincrement' => true]);
        $ratings->addColumn('ticket_id', 'integer');
        $ratings->addColumn('rating', 'smallint');
        $ratings->addColumn('comment', 'text', ['notnull' => false]);
        $ratings->addColumn('rated_by_type', 'string', ['length' => 255, 'notnull' => false]);
        $ratings->addColumn('rated_by_id', 'string', ['length' => 255, 'notnull' => false]);
        $ratings->addColumn('created_at', 'datetime_immutable');
        $ratings->setPrimaryKey(['id']);
        $ratings->addUniqueIndex(['ticket_id'], 'escalated_satisfaction_rating_ticket_unique');
        $ratings->addForeignKeyConstraint('escalated_tickets', ['ticket_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_escalated_satisfaction_ratings_ticket');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_satisfaction_ratings');
    }
}
