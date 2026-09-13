<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260625000005 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_side_conversations + escalated_side_conversation_replies tables';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $conversations = $schema->createTable('escalated_side_conversations');
        $conversations->addColumn('id', 'integer', ['autoincrement' => true]);
        $conversations->addColumn('ticket_id', 'integer');
        $conversations->addColumn('subject', 'string', ['length' => 255]);
        $conversations->addColumn('channel', 'string', ['length' => 32]);
        $conversations->addColumn('status', 'string', ['length' => 32]);
        $conversations->addColumn('created_by', 'string', ['length' => 255, 'notnull' => false]);
        $conversations->addColumn('created_at', 'datetime_immutable');
        $conversations->addColumn('updated_at', 'datetime_immutable');
        $conversations->setPrimaryKey(['id']);
        $conversations->addIndex(['ticket_id'], 'idx_side_conversation_ticket');
        $conversations->addForeignKeyConstraint('escalated_tickets', ['ticket_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_escalated_side_conversations_ticket');

        $replies = $schema->createTable('escalated_side_conversation_replies');
        $replies->addColumn('id', 'integer', ['autoincrement' => true]);
        $replies->addColumn('side_conversation_id', 'integer');
        $replies->addColumn('body', 'text');
        $replies->addColumn('author_id', 'string', ['length' => 255, 'notnull' => false]);
        $replies->addColumn('created_at', 'datetime_immutable');
        $replies->addColumn('updated_at', 'datetime_immutable');
        $replies->setPrimaryKey(['id']);
        $replies->addIndex(['side_conversation_id'], 'idx_side_conversation_reply_conversation');
        $replies->addForeignKeyConstraint('escalated_side_conversations', ['side_conversation_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_escalated_side_conversation_replies_conversation');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_side_conversation_replies');
        $schema->dropTable('escalated_side_conversations');
    }
}
