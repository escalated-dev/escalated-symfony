<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ticket subjects — host-app entities a ticket is *about* (Project, Customer, …),
 * distinct from the requester and the subject line (free text).
 */
final class Version20260529000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_ticket_subjects table for polymorphic ticket subject links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_ticket_subjects (
            id INT AUTO_INCREMENT NOT NULL,
            ticket_id INT NOT NULL,
            subject_type VARCHAR(255) NOT NULL,
            subject_id VARCHAR(255) NOT NULL,
            role VARCHAR(255) DEFAULT NULL,
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_ticket_subject_polymorphic (subject_type, subject_id),
            UNIQUE INDEX escalated_ticket_subject_unique (ticket_id, subject_type, subject_id),
            INDEX IDX_escalated_ticket_subjects_ticket (ticket_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE escalated_ticket_subjects ADD CONSTRAINT FK_escalated_ticket_subjects_ticket FOREIGN KEY (ticket_id) REFERENCES escalated_tickets (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalated_ticket_subjects DROP FOREIGN KEY FK_escalated_ticket_subjects_ticket');
        $this->addSql('DROP TABLE escalated_ticket_subjects');
    }
}
