<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds the Contact entity + nullable ticket.contact_id FK (Pattern B).
 *
 * Mirrors the convergence PRs across escalated-nestjs / laravel /
 * rails / django / adonis / dotnet / wordpress. Inline guest_name /
 * guest_email / guest_token fields on tickets remain for backwards
 * compatibility; a follow-up RunPython-equivalent migration (or
 * Symfony command) can backfill contact_id from guest_email.
 */
final class Version20260424000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_contacts + nullable ticket.contact_id FK';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_contacts (
            id INT AUTO_INCREMENT NOT NULL,
            email VARCHAR(320) NOT NULL,
            name VARCHAR(255) DEFAULT NULL,
            user_id INT DEFAULT NULL,
            metadata JSON NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_contact_user (user_id),
            UNIQUE INDEX UNIQ_contact_email (email),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE escalated_tickets ADD contact_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE escalated_tickets ADD CONSTRAINT fk_ticket_contact FOREIGN KEY (contact_id) REFERENCES escalated_contacts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_ticket_contact ON escalated_tickets (contact_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalated_tickets DROP FOREIGN KEY fk_ticket_contact');
        $this->addSql('DROP INDEX idx_ticket_contact ON escalated_tickets');
        $this->addSql('ALTER TABLE escalated_tickets DROP contact_id');
        $this->addSql('DROP TABLE escalated_contacts');
    }
}
