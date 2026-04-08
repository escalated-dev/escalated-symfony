<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260408000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add live chat support: chat fields on tickets, chat_sessions, and chat_routing_rules tables';
    }

    public function up(Schema $schema): void
    {
        // Add chat fields to tickets
        $tickets = $schema->getTable('escalated_tickets');
        $tickets->addColumn('channel', 'string', ['length' => 16, 'notnull' => false]);
        $tickets->addColumn('chat_ended_at', 'datetime_immutable', ['notnull' => false]);
        $tickets->addColumn('chat_metadata', 'json', ['notnull' => false]);
        $tickets->addIndex(['channel'], 'idx_ticket_channel');

        // Create chat_sessions table
        $sessions = $schema->createTable('escalated_chat_sessions');
        $sessions->addColumn('id', 'integer', ['autoincrement' => true]);
        $sessions->addColumn('ticket_id', 'integer', ['notnull' => true]);
        $sessions->addColumn('status', 'string', ['length' => 32, 'default' => 'waiting']);
        $sessions->addColumn('agent_id', 'integer', ['notnull' => false]);
        $sessions->addColumn('visitor_user_agent', 'string', ['length' => 255, 'notnull' => false]);
        $sessions->addColumn('visitor_ip', 'string', ['length' => 45, 'notnull' => false]);
        $sessions->addColumn('visitor_page_url', 'string', ['length' => 255, 'notnull' => false]);
        $sessions->addColumn('agent_joined_at', 'datetime_immutable', ['notnull' => false]);
        $sessions->addColumn('last_activity_at', 'datetime_immutable', ['notnull' => false]);
        $sessions->addColumn('ended_at', 'datetime_immutable', ['notnull' => false]);
        $sessions->addColumn('created_at', 'datetime_immutable');
        $sessions->setPrimaryKey(['id']);
        $sessions->addForeignKeyConstraint('escalated_tickets', ['ticket_id'], ['id'], ['onDelete' => 'CASCADE']);
        $sessions->addIndex(['status'], 'idx_chat_session_status');
        $sessions->addIndex(['agent_id'], 'idx_chat_session_agent');

        // Create chat_routing_rules table
        $rules = $schema->createTable('escalated_chat_routing_rules');
        $rules->addColumn('id', 'integer', ['autoincrement' => true]);
        $rules->addColumn('name', 'string', ['length' => 255]);
        $rules->addColumn('strategy', 'string', ['length' => 32, 'default' => 'round_robin']);
        $rules->addColumn('department_id', 'integer', ['notnull' => false]);
        $rules->addColumn('agent_ids', 'json', ['notnull' => false]);
        $rules->addColumn('priority', 'integer', ['default' => 0]);
        $rules->addColumn('max_concurrent_chats', 'integer', ['default' => 5]);
        $rules->addColumn('is_active', 'boolean', ['default' => true]);
        $rules->addColumn('created_at', 'datetime_immutable');
        $rules->setPrimaryKey(['id']);
        $rules->addIndex(['is_active', 'priority'], 'idx_routing_active_priority');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_chat_routing_rules');
        $schema->dropTable('escalated_chat_sessions');

        $tickets = $schema->getTable('escalated_tickets');
        $tickets->dropIndex('idx_ticket_channel');
        $tickets->dropColumn('channel');
        $tickets->dropColumn('chat_ended_at');
        $tickets->dropColumn('chat_metadata');
    }
}
