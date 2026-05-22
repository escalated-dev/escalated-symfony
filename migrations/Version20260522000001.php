<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Newsletter system: lists, list_members, templates, newsletters, deliveries, contacts opt-out';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_newsletter_lists (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            kind VARCHAR(16) NOT NULL,
            filter_json JSON DEFAULT NULL,
            created_by BIGINT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_nl_kind (kind),
            INDEX idx_nl_created_by (created_by),
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE escalated_newsletter_list_members (
            id INT AUTO_INCREMENT NOT NULL,
            list_id INT NOT NULL,
            contact_id INT NOT NULL,
            added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            added_by BIGINT DEFAULT NULL,
            UNIQUE INDEX uniq_nl_list_contact (list_id, contact_id),
            INDEX idx_nlm_contact (contact_id),
            PRIMARY KEY (id),
            CONSTRAINT fk_nlm_list FOREIGN KEY (list_id) REFERENCES escalated_newsletter_lists (id) ON DELETE CASCADE,
            CONSTRAINT fk_nlm_contact FOREIGN KEY (contact_id) REFERENCES escalated_contacts (id) ON DELETE CASCADE
        )');

        $this->addSql('CREATE TABLE escalated_newsletter_templates (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            theme VARCHAR(64) NOT NULL DEFAULT \'default\',
            subject_template VARCHAR(998) DEFAULT NULL,
            body_markdown LONGTEXT NOT NULL,
            merge_fields_schema JSON DEFAULT NULL,
            created_by BIGINT DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_nlt_theme (theme),
            INDEX idx_nlt_created_by (created_by),
            PRIMARY KEY (id)
        )');

        $this->addSql('CREATE TABLE escalated_newsletters (
            id INT AUTO_INCREMENT NOT NULL,
            subject VARCHAR(998) NOT NULL,
            from_email VARCHAR(320) NOT NULL,
            from_name VARCHAR(255) DEFAULT NULL,
            reply_to VARCHAR(320) DEFAULT NULL,
            target_list_id INT NOT NULL,
            template_id INT DEFAULT NULL,
            theme VARCHAR(64) DEFAULT NULL,
            body_markdown LONGTEXT DEFAULT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'draft\',
            scheduled_at DATETIME DEFAULT NULL,
            sent_at DATETIME DEFAULT NULL,
            created_by BIGINT DEFAULT NULL,
            sent_by BIGINT DEFAULT NULL,
            summary_total INT NOT NULL DEFAULT 0,
            summary_sent INT NOT NULL DEFAULT 0,
            summary_opened INT NOT NULL DEFAULT 0,
            summary_clicked INT NOT NULL DEFAULT 0,
            summary_bounced INT NOT NULL DEFAULT 0,
            summary_complained INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX idx_n_status (status),
            INDEX idx_n_scheduled_at (scheduled_at),
            INDEX idx_n_status_sched (status, scheduled_at),
            INDEX idx_n_created_by (created_by),
            PRIMARY KEY (id),
            CONSTRAINT fk_n_list FOREIGN KEY (target_list_id) REFERENCES escalated_newsletter_lists (id),
            CONSTRAINT fk_n_template FOREIGN KEY (template_id) REFERENCES escalated_newsletter_templates (id) ON DELETE SET NULL
        )');

        $this->addSql('CREATE TABLE escalated_newsletter_deliveries (
            id BIGINT AUTO_INCREMENT NOT NULL,
            newsletter_id INT NOT NULL,
            contact_id INT NOT NULL,
            email_at_send VARCHAR(320) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT \'pending\',
            tracking_token VARCHAR(40) NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            opened_at DATETIME DEFAULT NULL,
            last_clicked_at DATETIME DEFAULT NULL,
            clicks_count INT NOT NULL DEFAULT 0,
            bounce_reason LONGTEXT DEFAULT NULL,
            failure_reason LONGTEXT DEFAULT NULL,
            attempt_count SMALLINT NOT NULL DEFAULT 0,
            claimed_at DATETIME DEFAULT NULL,
            is_test TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            UNIQUE INDEX uniq_nd_token (tracking_token),
            INDEX idx_nd_nl_status (newsletter_id, status),
            INDEX idx_nd_contact (contact_id),
            INDEX idx_nd_status_claimed (status, claimed_at),
            PRIMARY KEY (id),
            CONSTRAINT fk_nd_newsletter FOREIGN KEY (newsletter_id) REFERENCES escalated_newsletters (id) ON DELETE CASCADE,
            CONSTRAINT fk_nd_contact FOREIGN KEY (contact_id) REFERENCES escalated_contacts (id) ON DELETE CASCADE
        )');

        $this->addSql('ALTER TABLE escalated_contacts ADD COLUMN marketing_opt_out_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_contact_opt_out ON escalated_contacts (marketing_opt_out_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_contact_opt_out ON escalated_contacts');
        $this->addSql('ALTER TABLE escalated_contacts DROP COLUMN marketing_opt_out_at');
        $this->addSql('DROP TABLE escalated_newsletter_deliveries');
        $this->addSql('DROP TABLE escalated_newsletters');
        $this->addSql('DROP TABLE escalated_newsletter_templates');
        $this->addSql('DROP TABLE escalated_newsletter_list_members');
        $this->addSql('DROP TABLE escalated_newsletter_lists');
    }
}
