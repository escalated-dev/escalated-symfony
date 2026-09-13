<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260630000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_ticket_followers table';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $followers = $schema->createTable('escalated_ticket_followers');
        $followers->addColumn('id', 'integer', ['autoincrement' => true]);
        $followers->addColumn('ticket_id', 'integer');
        // The host user's key, declared like every other user reference in
        // these migrations (and like the entity's default user-id type).
        $followers->addColumn('user_id', 'integer');
        $followers->addColumn('created_at', 'datetime_immutable');
        $followers->setPrimaryKey(['id']);
        $followers->addUniqueIndex(['ticket_id', 'user_id'], 'UNIQ_ticket_followers_ticket_user');
        $followers->addIndex(['user_id'], 'idx_ticket_follower_user');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_ticket_followers');
    }
}
