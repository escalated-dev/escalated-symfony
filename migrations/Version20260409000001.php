<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260409000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add parity gap tables: email_channels, custom_fields, custom_field_values, custom_objects, custom_object_records, audit_logs, business_schedules, holidays, two_factors, workflows, workflow_logs, delayed_actions';
    }

    public function up(Schema $schema): void
    {
        // Email Channels
        $emailChannels = $schema->createTable('escalated_email_channels');
        $emailChannels->addColumn('id', 'integer', ['autoincrement' => true]);
        $emailChannels->addColumn('email_address', 'string', ['length' => 255]);
        $emailChannels->addColumn('display_name', 'string', ['length' => 255, 'notnull' => false]);
        $emailChannels->addColumn('department_id', 'integer', ['notnull' => false]);
        $emailChannels->addColumn('is_default', 'boolean', ['default' => false]);
        $emailChannels->addColumn('is_verified', 'boolean', ['default' => false]);
        $emailChannels->addColumn('dkim_status', 'string', ['length' => 32, 'default' => 'pending']);
        $emailChannels->addColumn('dkim_public_key', 'text', ['notnull' => false]);
        $emailChannels->addColumn('dkim_selector', 'string', ['length' => 255, 'notnull' => false]);
        $emailChannels->addColumn('reply_to_address', 'string', ['length' => 255, 'notnull' => false]);
        $emailChannels->addColumn('smtp_protocol', 'string', ['length' => 32, 'default' => 'tls']);
        $emailChannels->addColumn('smtp_host', 'string', ['length' => 255, 'notnull' => false]);
        $emailChannels->addColumn('smtp_port', 'integer', ['notnull' => false]);
        $emailChannels->addColumn('smtp_username', 'string', ['length' => 255, 'notnull' => false]);
        $emailChannels->addColumn('smtp_password', 'string', ['length' => 255, 'notnull' => false]);
        $emailChannels->addColumn('is_active', 'boolean', ['default' => true]);
        $emailChannels->addColumn('created_at', 'datetime_immutable');
        $emailChannels->addColumn('updated_at', 'datetime_immutable');
        $emailChannels->setPrimaryKey(['id']);
        $emailChannels->addIndex(['department_id'], 'idx_email_channel_dept');
        $emailChannels->addIndex(['is_active'], 'idx_email_channel_active');
        $emailChannels->addForeignKeyConstraint('escalated_departments', ['department_id'], ['id'], ['onDelete' => 'SET NULL']);

        // Custom Fields
        $customFields = $schema->createTable('escalated_custom_fields');
        $customFields->addColumn('id', 'integer', ['autoincrement' => true]);
        $customFields->addColumn('name', 'string', ['length' => 255]);
        $customFields->addColumn('slug', 'string', ['length' => 255]);
        $customFields->addColumn('field_type', 'string', ['length' => 50, 'default' => 'text']);
        $customFields->addColumn('description', 'text', ['notnull' => false]);
        $customFields->addColumn('is_required', 'boolean', ['default' => false]);
        $customFields->addColumn('options', 'json', ['notnull' => false]);
        $customFields->addColumn('default_value', 'string', ['length' => 255, 'notnull' => false]);
        $customFields->addColumn('entity_type', 'string', ['length' => 50, 'default' => 'ticket']);
        $customFields->addColumn('position', 'integer', ['default' => 0]);
        $customFields->addColumn('is_active', 'boolean', ['default' => true]);
        $customFields->addColumn('created_at', 'datetime_immutable');
        $customFields->addColumn('updated_at', 'datetime_immutable');
        $customFields->setPrimaryKey(['id']);
        $customFields->addUniqueIndex(['slug'], 'uniq_custom_field_slug');
        $customFields->addIndex(['entity_type'], 'idx_custom_field_entity_type');

        // Custom Field Values
        $cfv = $schema->createTable('escalated_custom_field_values');
        $cfv->addColumn('id', 'integer', ['autoincrement' => true]);
        $cfv->addColumn('custom_field_id', 'integer');
        $cfv->addColumn('entity_type', 'string', ['length' => 50, 'default' => 'ticket']);
        $cfv->addColumn('entity_id', 'integer');
        $cfv->addColumn('value', 'text', ['notnull' => false]);
        $cfv->addColumn('created_at', 'datetime_immutable');
        $cfv->addColumn('updated_at', 'datetime_immutable');
        $cfv->setPrimaryKey(['id']);
        $cfv->addUniqueIndex(['custom_field_id', 'entity_type', 'entity_id'], 'unique_field_entity');
        $cfv->addForeignKeyConstraint('escalated_custom_fields', ['custom_field_id'], ['id'], ['onDelete' => 'CASCADE']);

        // Custom Objects
        $co = $schema->createTable('escalated_custom_objects');
        $co->addColumn('id', 'integer', ['autoincrement' => true]);
        $co->addColumn('name', 'string', ['length' => 255]);
        $co->addColumn('slug', 'string', ['length' => 255]);
        $co->addColumn('description', 'text', ['notnull' => false]);
        $co->addColumn('field_definitions', 'json', ['notnull' => false]);
        $co->addColumn('is_active', 'boolean', ['default' => true]);
        $co->addColumn('created_at', 'datetime_immutable');
        $co->addColumn('updated_at', 'datetime_immutable');
        $co->setPrimaryKey(['id']);
        $co->addUniqueIndex(['slug'], 'uniq_custom_object_slug');

        // Custom Object Records
        $cor = $schema->createTable('escalated_custom_object_records');
        $cor->addColumn('id', 'integer', ['autoincrement' => true]);
        $cor->addColumn('custom_object_id', 'integer');
        $cor->addColumn('title', 'string', ['length' => 255, 'notnull' => false]);
        $cor->addColumn('data', 'json');
        $cor->addColumn('linked_entity_type', 'string', ['length' => 50, 'notnull' => false]);
        $cor->addColumn('linked_entity_id', 'integer', ['notnull' => false]);
        $cor->addColumn('created_at', 'datetime_immutable');
        $cor->addColumn('updated_at', 'datetime_immutable');
        $cor->setPrimaryKey(['id']);
        $cor->addIndex(['custom_object_id'], 'idx_cor_object');
        $cor->addIndex(['linked_entity_type', 'linked_entity_id'], 'idx_cor_linked');
        $cor->addForeignKeyConstraint('escalated_custom_objects', ['custom_object_id'], ['id'], ['onDelete' => 'CASCADE']);

        // Audit Logs
        $al = $schema->createTable('escalated_audit_logs');
        $al->addColumn('id', 'integer', ['autoincrement' => true]);
        $al->addColumn('action', 'string', ['length' => 255]);
        $al->addColumn('entity_type', 'string', ['length' => 50]);
        $al->addColumn('entity_id', 'integer', ['notnull' => false]);
        $al->addColumn('performer_type', 'string', ['length' => 50, 'notnull' => false]);
        $al->addColumn('performer_id', 'integer', ['notnull' => false]);
        $al->addColumn('old_values', 'json', ['notnull' => false]);
        $al->addColumn('new_values', 'json', ['notnull' => false]);
        $al->addColumn('ip_address', 'string', ['length' => 45, 'notnull' => false]);
        $al->addColumn('user_agent', 'string', ['length' => 255, 'notnull' => false]);
        $al->addColumn('created_at', 'datetime_immutable');
        $al->setPrimaryKey(['id']);
        $al->addIndex(['entity_type', 'entity_id'], 'idx_audit_entity');
        $al->addIndex(['performer_type', 'performer_id'], 'idx_audit_performer');
        $al->addIndex(['created_at'], 'idx_audit_created');

        // Business Schedules
        $bs = $schema->createTable('escalated_business_schedules');
        $bs->addColumn('id', 'integer', ['autoincrement' => true]);
        $bs->addColumn('name', 'string', ['length' => 255]);
        $bs->addColumn('timezone', 'string', ['length' => 64, 'default' => 'UTC']);
        $bs->addColumn('hours', 'json');
        $bs->addColumn('is_default', 'boolean', ['default' => false]);
        $bs->addColumn('is_active', 'boolean', ['default' => true]);
        $bs->addColumn('created_at', 'datetime_immutable');
        $bs->addColumn('updated_at', 'datetime_immutable');
        $bs->setPrimaryKey(['id']);

        // Holidays
        $h = $schema->createTable('escalated_holidays');
        $h->addColumn('id', 'integer', ['autoincrement' => true]);
        $h->addColumn('business_schedule_id', 'integer');
        $h->addColumn('name', 'string', ['length' => 255]);
        $h->addColumn('date', 'date_immutable');
        $h->addColumn('is_recurring', 'boolean', ['default' => false]);
        $h->addColumn('created_at', 'datetime_immutable');
        $h->setPrimaryKey(['id']);
        $h->addIndex(['business_schedule_id'], 'idx_holiday_schedule');
        $h->addForeignKeyConstraint('escalated_business_schedules', ['business_schedule_id'], ['id'], ['onDelete' => 'CASCADE']);

        // Two Factors
        $tf = $schema->createTable('escalated_two_factors');
        $tf->addColumn('id', 'integer', ['autoincrement' => true]);
        $tf->addColumn('user_id', 'integer');
        $tf->addColumn('method', 'string', ['length' => 32, 'default' => 'totp']);
        $tf->addColumn('secret', 'string', ['length' => 255, 'notnull' => false]);
        $tf->addColumn('recovery_codes', 'json', ['notnull' => false]);
        $tf->addColumn('is_enabled', 'boolean', ['default' => false]);
        $tf->addColumn('verified_at', 'datetime_immutable', ['notnull' => false]);
        $tf->addColumn('created_at', 'datetime_immutable');
        $tf->addColumn('updated_at', 'datetime_immutable');
        $tf->setPrimaryKey(['id']);
        $tf->addIndex(['user_id'], 'idx_two_factor_user');

        // Workflows
        $wf = $schema->createTable('escalated_workflows');
        $wf->addColumn('id', 'integer', ['autoincrement' => true]);
        $wf->addColumn('name', 'string', ['length' => 255]);
        $wf->addColumn('description', 'text', ['notnull' => false]);
        $wf->addColumn('trigger_event', 'string', ['length' => 255]);
        $wf->addColumn('conditions', 'json');
        $wf->addColumn('actions', 'json');
        $wf->addColumn('position', 'integer', ['default' => 0]);
        $wf->addColumn('is_active', 'boolean', ['default' => true]);
        $wf->addColumn('stop_on_match', 'boolean', ['default' => false]);
        $wf->addColumn('created_at', 'datetime_immutable');
        $wf->addColumn('updated_at', 'datetime_immutable');
        $wf->setPrimaryKey(['id']);
        $wf->addIndex(['trigger_event'], 'idx_workflow_trigger');
        $wf->addIndex(['is_active'], 'idx_workflow_active');

        // Workflow Logs
        $wl = $schema->createTable('escalated_workflow_logs');
        $wl->addColumn('id', 'integer', ['autoincrement' => true]);
        $wl->addColumn('workflow_id', 'integer');
        $wl->addColumn('ticket_id', 'integer');
        $wl->addColumn('trigger_event', 'string', ['length' => 255]);
        $wl->addColumn('status', 'string', ['length' => 32]);
        $wl->addColumn('actions_executed', 'json');
        $wl->addColumn('error_message', 'text', ['notnull' => false]);
        $wl->addColumn('created_at', 'datetime_immutable');
        $wl->setPrimaryKey(['id']);
        $wl->addIndex(['workflow_id'], 'idx_wflog_workflow');
        $wl->addIndex(['ticket_id'], 'idx_wflog_ticket');

        // Delayed Actions
        $da = $schema->createTable('escalated_delayed_actions');
        $da->addColumn('id', 'integer', ['autoincrement' => true]);
        $da->addColumn('workflow_id', 'integer');
        $da->addColumn('ticket_id', 'integer');
        $da->addColumn('action_data', 'json');
        $da->addColumn('execute_at', 'datetime_immutable');
        $da->addColumn('executed', 'boolean', ['default' => false]);
        $da->addColumn('created_at', 'datetime_immutable');
        $da->setPrimaryKey(['id']);
        $da->addIndex(['executed', 'execute_at'], 'idx_delayed_pending');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_delayed_actions');
        $schema->dropTable('escalated_workflow_logs');
        $schema->dropTable('escalated_workflows');
        $schema->dropTable('escalated_two_factors');
        $schema->dropTable('escalated_holidays');
        $schema->dropTable('escalated_business_schedules');
        $schema->dropTable('escalated_audit_logs');
        $schema->dropTable('escalated_custom_object_records');
        $schema->dropTable('escalated_custom_objects');
        $schema->dropTable('escalated_custom_field_values');
        $schema->dropTable('escalated_custom_fields');
        $schema->dropTable('escalated_email_channels');
    }
}
