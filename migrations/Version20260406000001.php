<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

final class Version20260406000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Create Escalated support ticket system tables';
    }

    public function up(Schema $schema): void
    {
        // Departments
        $departments = $schema->createTable($this->table('departments'));
        $departments->addColumn('id', 'integer', ['autoincrement' => true]);
        $departments->addColumn('name', 'string', ['length' => 255]);
        $departments->addColumn('slug', 'string', ['length' => 255]);
        $departments->addColumn('description', 'text', ['notnull' => false]);
        $departments->addColumn('is_active', 'boolean', ['default' => true]);
        $departments->addColumn('created_at', 'datetime_immutable');
        $departments->addColumn('updated_at', 'datetime_immutable');
        $departments->setPrimaryKey(['id']);
        $departments->addUniqueIndex(['slug']);

        // SLA Policies
        $slaPolicies = $schema->createTable($this->table('sla_policies'));
        $slaPolicies->addColumn('id', 'integer', ['autoincrement' => true]);
        $slaPolicies->addColumn('name', 'string', ['length' => 255]);
        $slaPolicies->addColumn('description', 'text', ['notnull' => false]);
        $slaPolicies->addColumn('first_response_hours', 'json');
        $slaPolicies->addColumn('resolution_hours', 'json');
        $slaPolicies->addColumn('business_hours_only', 'boolean', ['default' => false]);
        $slaPolicies->addColumn('is_default', 'boolean', ['default' => false]);
        $slaPolicies->addColumn('is_active', 'boolean', ['default' => true]);
        $slaPolicies->addColumn('created_at', 'datetime_immutable');
        $slaPolicies->addColumn('updated_at', 'datetime_immutable');
        $slaPolicies->setPrimaryKey(['id']);

        // Tags
        $tags = $schema->createTable($this->table('tags'));
        $tags->addColumn('id', 'integer', ['autoincrement' => true]);
        $tags->addColumn('name', 'string', ['length' => 255]);
        $tags->addColumn('slug', 'string', ['length' => 255]);
        $tags->addColumn('color', 'string', ['length' => 7, 'notnull' => false]);
        $tags->addColumn('created_at', 'datetime_immutable');
        $tags->addColumn('updated_at', 'datetime_immutable');
        $tags->setPrimaryKey(['id']);
        $tags->addUniqueIndex(['slug']);

        // Agent Profiles
        $agentProfiles = $schema->createTable($this->table('agent_profiles'));
        $agentProfiles->addColumn('id', 'integer', ['autoincrement' => true]);
        $agentProfiles->addColumn('user_id', 'integer');
        $agentProfiles->addColumn('agent_type', 'string', ['length' => 16, 'default' => 'full']);
        $agentProfiles->addColumn('max_tickets', 'integer', ['notnull' => false]);
        $agentProfiles->addColumn('created_at', 'datetime_immutable');
        $agentProfiles->addColumn('updated_at', 'datetime_immutable');
        $agentProfiles->setPrimaryKey(['id']);
        $agentProfiles->addUniqueIndex(['user_id']);

        // Tickets
        $tickets = $schema->createTable($this->table('tickets'));
        $tickets->addColumn('id', 'integer', ['autoincrement' => true]);
        $tickets->addColumn('reference', 'string', ['length' => 32]);
        $tickets->addColumn('subject', 'string', ['length' => 255]);
        $tickets->addColumn('description', 'text', ['notnull' => false]);
        $tickets->addColumn('status', 'string', ['length' => 32, 'default' => 'open']);
        $tickets->addColumn('priority', 'string', ['length' => 16, 'default' => 'medium']);
        $tickets->addColumn('ticket_type', 'string', ['length' => 32, 'notnull' => false]);
        $tickets->addColumn('requester_id', 'integer', ['notnull' => false]);
        $tickets->addColumn('requester_class', 'string', ['length' => 255, 'notnull' => false]);
        $tickets->addColumn('assigned_to', 'integer', ['notnull' => false]);
        $tickets->addColumn('department_id', 'integer', ['notnull' => false]);
        $tickets->addColumn('sla_policy_id', 'integer', ['notnull' => false]);
        $tickets->addColumn('guest_name', 'string', ['length' => 255, 'notnull' => false]);
        $tickets->addColumn('guest_email', 'string', ['length' => 255, 'notnull' => false]);
        $tickets->addColumn('guest_token', 'string', ['length' => 64, 'notnull' => false]);
        $tickets->addColumn('first_response_at', 'datetime_immutable', ['notnull' => false]);
        $tickets->addColumn('first_response_due_at', 'datetime_immutable', ['notnull' => false]);
        $tickets->addColumn('resolution_due_at', 'datetime_immutable', ['notnull' => false]);
        $tickets->addColumn('sla_first_response_breached', 'boolean', ['default' => false]);
        $tickets->addColumn('sla_resolution_breached', 'boolean', ['default' => false]);
        $tickets->addColumn('resolved_at', 'datetime_immutable', ['notnull' => false]);
        $tickets->addColumn('closed_at', 'datetime_immutable', ['notnull' => false]);
        $tickets->addColumn('metadata', 'json', ['notnull' => false]);
        $tickets->addColumn('created_at', 'datetime_immutable');
        $tickets->addColumn('updated_at', 'datetime_immutable');
        $tickets->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        $tickets->setPrimaryKey(['id']);
        $tickets->addUniqueIndex(['reference']);
        $tickets->addIndex(['status'], 'idx_ticket_status');
        $tickets->addIndex(['priority'], 'idx_ticket_priority');
        $tickets->addIndex(['assigned_to'], 'idx_ticket_assigned');
        $tickets->addIndex(['requester_id'], 'idx_ticket_requester');
        $tickets->addForeignKeyConstraint($this->table('departments'), ['department_id'], ['id'], ['onDelete' => 'SET NULL']);
        $tickets->addForeignKeyConstraint($this->table('sla_policies'), ['sla_policy_id'], ['id'], ['onDelete' => 'SET NULL']);

        // Replies
        $replies = $schema->createTable($this->table('replies'));
        $replies->addColumn('id', 'integer', ['autoincrement' => true]);
        $replies->addColumn('ticket_id', 'integer');
        $replies->addColumn('author_id', 'integer', ['notnull' => false]);
        $replies->addColumn('author_class', 'string', ['length' => 255, 'notnull' => false]);
        $replies->addColumn('body', 'text');
        $replies->addColumn('is_internal_note', 'boolean', ['default' => false]);
        $replies->addColumn('is_pinned', 'boolean', ['default' => false]);
        $replies->addColumn('type', 'string', ['length' => 16, 'default' => 'reply']);
        $replies->addColumn('metadata', 'json', ['notnull' => false]);
        $replies->addColumn('created_at', 'datetime_immutable');
        $replies->addColumn('updated_at', 'datetime_immutable');
        $replies->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        $replies->setPrimaryKey(['id']);
        $replies->addIndex(['ticket_id'], 'idx_reply_ticket');
        $replies->addForeignKeyConstraint($this->table('tickets'), ['ticket_id'], ['id'], ['onDelete' => 'CASCADE']);

        // Ticket-Tag pivot
        $ticketTag = $schema->createTable($this->table('ticket_tag'));
        $ticketTag->addColumn('ticket_id', 'integer');
        $ticketTag->addColumn('tag_id', 'integer');
        $ticketTag->setPrimaryKey(['ticket_id', 'tag_id']);
        $ticketTag->addForeignKeyConstraint($this->table('tickets'), ['ticket_id'], ['id'], ['onDelete' => 'CASCADE']);
        $ticketTag->addForeignKeyConstraint($this->table('tags'), ['tag_id'], ['id'], ['onDelete' => 'CASCADE']);

        // Ticket Activities
        $activities = $schema->createTable($this->table('ticket_activities'));
        $activities->addColumn('id', 'integer', ['autoincrement' => true]);
        $activities->addColumn('ticket_id', 'integer');
        $activities->addColumn('type', 'string', ['length' => 32]);
        $activities->addColumn('causer_id', 'integer', ['notnull' => false]);
        $activities->addColumn('causer_class', 'string', ['length' => 255, 'notnull' => false]);
        $activities->addColumn('properties', 'json', ['notnull' => false]);
        $activities->addColumn('created_at', 'datetime_immutable');
        $activities->setPrimaryKey(['id']);
        $activities->addIndex(['ticket_id'], 'idx_activity_ticket');
        $activities->addForeignKeyConstraint($this->table('tickets'), ['ticket_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->table('ticket_activities'));
        $schema->dropTable($this->table('ticket_tag'));
        $schema->dropTable($this->table('replies'));
        $schema->dropTable($this->table('tickets'));
        $schema->dropTable($this->table('agent_profiles'));
        $schema->dropTable($this->table('tags'));
        $schema->dropTable($this->table('sla_policies'));
        $schema->dropTable($this->table('departments'));
    }
}
