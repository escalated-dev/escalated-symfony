<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260522000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Newsletter system: lists, list_members, templates, newsletters, deliveries, contacts opt-out';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $lists = $schema->createTable('escalated_newsletter_lists');
        $lists->addColumn('id', 'integer', ['autoincrement' => true]);
        $lists->addColumn('name', 'string', ['length' => 255]);
        $lists->addColumn('description', 'text', ['notnull' => false]);
        $lists->addColumn('kind', 'string', ['length' => 16]);
        $lists->addColumn('filter_json', 'json', ['notnull' => false]);
        $lists->addColumn('created_by', 'integer', ['notnull' => false]);
        $lists->addColumn('created_at', 'datetime');
        $lists->addColumn('updated_at', 'datetime');
        $lists->setPrimaryKey(['id']);
        $lists->addIndex(['kind'], 'idx_nl_kind');
        $lists->addIndex(['created_by'], 'idx_nl_created_by');

        $members = $schema->createTable('escalated_newsletter_list_members');
        $members->addColumn('id', 'integer', ['autoincrement' => true]);
        $members->addColumn('list_id', 'integer');
        $members->addColumn('contact_id', 'integer');
        $members->addColumn('added_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP']);
        $members->addColumn('added_by', 'integer', ['notnull' => false]);
        $members->setPrimaryKey(['id']);
        $members->addUniqueIndex(['list_id', 'contact_id'], 'uniq_nl_list_contact');
        $members->addIndex(['contact_id'], 'idx_nlm_contact');
        $members->addForeignKeyConstraint('escalated_newsletter_lists', ['list_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_nlm_list');
        $members->addForeignKeyConstraint('escalated_contacts', ['contact_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_nlm_contact');

        $templates = $schema->createTable('escalated_newsletter_templates');
        $templates->addColumn('id', 'integer', ['autoincrement' => true]);
        $templates->addColumn('name', 'string', ['length' => 255]);
        $templates->addColumn('theme', 'string', ['length' => 64, 'default' => 'default']);
        $templates->addColumn('subject_template', 'string', ['length' => 998, 'notnull' => false]);
        $templates->addColumn('body_markdown', 'text');
        $templates->addColumn('merge_fields_schema', 'json', ['notnull' => false]);
        $templates->addColumn('created_by', 'integer', ['notnull' => false]);
        $templates->addColumn('created_at', 'datetime');
        $templates->addColumn('updated_at', 'datetime');
        $templates->setPrimaryKey(['id']);
        $templates->addIndex(['theme'], 'idx_nlt_theme');
        $templates->addIndex(['created_by'], 'idx_nlt_created_by');

        $newsletters = $schema->createTable('escalated_newsletters');
        $newsletters->addColumn('id', 'integer', ['autoincrement' => true]);
        $newsletters->addColumn('subject', 'string', ['length' => 998]);
        $newsletters->addColumn('from_email', 'string', ['length' => 320]);
        $newsletters->addColumn('from_name', 'string', ['length' => 255, 'notnull' => false]);
        $newsletters->addColumn('reply_to', 'string', ['length' => 320, 'notnull' => false]);
        $newsletters->addColumn('target_list_id', 'integer');
        $newsletters->addColumn('template_id', 'integer', ['notnull' => false]);
        $newsletters->addColumn('theme', 'string', ['length' => 64, 'notnull' => false]);
        $newsletters->addColumn('body_markdown', 'text', ['notnull' => false]);
        $newsletters->addColumn('status', 'string', ['length' => 16, 'default' => 'draft']);
        $newsletters->addColumn('scheduled_at', 'datetime', ['notnull' => false]);
        $newsletters->addColumn('sent_at', 'datetime', ['notnull' => false]);
        $newsletters->addColumn('created_by', 'integer', ['notnull' => false]);
        $newsletters->addColumn('sent_by', 'integer', ['notnull' => false]);
        foreach (['summary_total', 'summary_sent', 'summary_opened', 'summary_clicked', 'summary_bounced', 'summary_complained'] as $counter) {
            $newsletters->addColumn($counter, 'integer', ['default' => 0]);
        }
        $newsletters->addColumn('created_at', 'datetime');
        $newsletters->addColumn('updated_at', 'datetime');
        $newsletters->setPrimaryKey(['id']);
        $newsletters->addIndex(['status'], 'idx_n_status');
        $newsletters->addIndex(['scheduled_at'], 'idx_n_scheduled_at');
        $newsletters->addIndex(['status', 'scheduled_at'], 'idx_n_status_sched');
        $newsletters->addIndex(['created_by'], 'idx_n_created_by');
        $newsletters->addForeignKeyConstraint('escalated_newsletter_lists', ['target_list_id'], ['id'], [], 'fk_n_list');
        $newsletters->addForeignKeyConstraint('escalated_newsletter_templates', ['template_id'], ['id'], ['onDelete' => 'SET NULL'], 'fk_n_template');

        $deliveries = $schema->createTable('escalated_newsletter_deliveries');
        $deliveries->addColumn('id', 'bigint', ['autoincrement' => true]);
        $deliveries->addColumn('newsletter_id', 'integer');
        $deliveries->addColumn('contact_id', 'integer');
        $deliveries->addColumn('email_at_send', 'string', ['length' => 320]);
        $deliveries->addColumn('status', 'string', ['length' => 16, 'default' => 'pending']);
        $deliveries->addColumn('tracking_token', 'string', ['length' => 40]);
        $deliveries->addColumn('sent_at', 'datetime', ['notnull' => false]);
        $deliveries->addColumn('opened_at', 'datetime', ['notnull' => false]);
        $deliveries->addColumn('last_clicked_at', 'datetime', ['notnull' => false]);
        $deliveries->addColumn('clicks_count', 'integer', ['default' => 0]);
        $deliveries->addColumn('bounce_reason', 'text', ['notnull' => false]);
        $deliveries->addColumn('failure_reason', 'text', ['notnull' => false]);
        $deliveries->addColumn('attempt_count', 'smallint', ['default' => 0]);
        $deliveries->addColumn('claimed_at', 'datetime', ['notnull' => false]);
        $deliveries->addColumn('is_test', 'boolean', ['default' => false]);
        $deliveries->addColumn('created_at', 'datetime');
        $deliveries->setPrimaryKey(['id']);
        $deliveries->addUniqueIndex(['tracking_token'], 'uniq_nd_token');
        $deliveries->addIndex(['newsletter_id', 'status'], 'idx_nd_nl_status');
        $deliveries->addIndex(['contact_id'], 'idx_nd_contact');
        $deliveries->addIndex(['status', 'claimed_at'], 'idx_nd_status_claimed');
        $deliveries->addForeignKeyConstraint('escalated_newsletters', ['newsletter_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_nd_newsletter');
        $deliveries->addForeignKeyConstraint('escalated_contacts', ['contact_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_nd_contact');

        $contacts = $schema->getTable('escalated_contacts');
        $contacts->addColumn('marketing_opt_out_at', 'datetime', ['notnull' => false]);
        $contacts->addIndex(['marketing_opt_out_at'], 'idx_contact_opt_out');
    }

    public function down(Schema $schema): void
    {
        $contacts = $schema->getTable('escalated_contacts');
        $contacts->dropIndex('idx_contact_opt_out');
        $contacts->dropColumn('marketing_opt_out_at');

        $schema->dropTable('escalated_newsletter_deliveries');
        $schema->dropTable('escalated_newsletters');
        $schema->dropTable('escalated_newsletter_templates');
        $schema->dropTable('escalated_newsletter_list_members');
        $schema->dropTable('escalated_newsletter_lists');
    }
}
