<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;
use Escalated\Symfony\Doctrine\UserIdType;

/**
 * The agent panel's data (ADR 2026-11-05-agent-panel-data):
 *
 * - `tickets.last_customer_activity_at` and `ticket_reads`, per-agent unread
 *   markers. Existing tickets get no activity, so none turn unread on upgrade;
 * - `customer_presence`, when each customer was last seen;
 * - `agent_profiles.chat_status`, the agent's own Online / Away / Offline
 *   status (`offline` until set);
 * - `notifications`, the agents' in-app notifications.
 */
final class Version20261105000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add the agent panel data: unread markers, customer presence, agent status, notifications';
    }

    public function up(Schema $schema): void
    {
        $tickets = $schema->getTable($this->table('tickets'));
        $tickets->addColumn('last_customer_activity_at', 'datetime_immutable', ['notnull' => false]);
        $tickets->addIndex(['last_customer_activity_at'], 'idx_ticket_customer_activity');

        $profiles = $schema->getTable($this->table('agent_profiles'));
        $profiles->addColumn('chat_status', 'string', ['length' => 16, 'default' => 'offline']);

        $reads = $schema->createTable($this->table('ticket_reads'));
        $reads->addColumn('id', 'integer', ['autoincrement' => true]);
        $reads->addColumn('user_id', UserIdType::NAME);
        $reads->addColumn('ticket_id', 'integer');
        $reads->addColumn('last_read_at', 'datetime_immutable', ['notnull' => false]);
        $reads->addColumn('created_at', 'datetime_immutable');
        $reads->addColumn('updated_at', 'datetime_immutable');
        $reads->setPrimaryKey(['id']);
        $reads->addUniqueIndex(['user_id', 'ticket_id'], $this->generatedName('uniq', 'ticket_reads', ['user_id', 'ticket_id']));
        $reads->addIndex(['ticket_id'], $this->generatedName('idx', 'ticket_reads', ['ticket_id']));
        $reads->addForeignKeyConstraint(
            $this->table('tickets'),
            ['ticket_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            $this->generatedName('fk', 'ticket_reads', ['ticket_id']),
        );

        $presence = $schema->createTable($this->table('customer_presence'));
        $presence->addColumn('id', 'integer', ['autoincrement' => true]);
        $presence->addColumn('subject_type', 'string', ['length' => 191]);
        $presence->addColumn('subject_id', 'string', ['length' => 64]);
        $presence->addColumn('last_seen_at', 'datetime_immutable');
        $presence->addColumn('created_at', 'datetime_immutable');
        $presence->addColumn('updated_at', 'datetime_immutable');
        $presence->setPrimaryKey(['id']);
        $presence->addUniqueIndex(['subject_type', 'subject_id'], $this->generatedName('uniq', 'customer_presence', ['subject_type', 'subject_id']));

        $notifications = $schema->createTable($this->table('notifications'));
        $notifications->addColumn('id', 'string', ['length' => 36]);
        $notifications->addColumn('user_id', UserIdType::NAME);
        $notifications->addColumn('type', 'string', ['length' => 64]);
        $notifications->addColumn('data', 'json');
        $notifications->addColumn('read_at', 'datetime_immutable', ['notnull' => false]);
        $notifications->addColumn('created_at', 'datetime_immutable');
        $notifications->setPrimaryKey(['id']);
        $notifications->addIndex(['user_id', 'read_at'], $this->generatedName('idx', 'notifications', ['user_id', 'read_at']));
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->table('notifications'));
        $schema->dropTable($this->table('customer_presence'));
        $schema->dropTable($this->table('ticket_reads'));
        $schema->getTable($this->table('agent_profiles'))->dropColumn('chat_status');
        $tickets = $schema->getTable($this->table('tickets'));
        $tickets->dropIndex('idx_ticket_customer_activity');
        $tickets->dropColumn('last_customer_activity_at');
    }
}
