<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ticket-to-ticket links (problem/incident, parent/child, related). Mirrors
 * the Laravel ticket_links table. Distinct from escalated_ticket_subjects,
 * which links a ticket to a host-app subject.
 */
final class Version20260625000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_ticket_links table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_ticket_links (
            id INT AUTO_INCREMENT NOT NULL,
            parent_ticket_id INT NOT NULL,
            child_ticket_id INT NOT NULL,
            link_type VARCHAR(32) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_ticket_link_parent (parent_ticket_id),
            INDEX idx_ticket_link_child (child_ticket_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE escalated_ticket_links
            ADD CONSTRAINT FK_escalated_ticket_links_parent
            FOREIGN KEY (parent_ticket_id) REFERENCES escalated_tickets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE escalated_ticket_links
            ADD CONSTRAINT FK_escalated_ticket_links_child
            FOREIGN KEY (child_ticket_id) REFERENCES escalated_tickets (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalated_ticket_links DROP FOREIGN KEY FK_escalated_ticket_links_parent');
        $this->addSql('ALTER TABLE escalated_ticket_links DROP FOREIGN KEY FK_escalated_ticket_links_child');
        $this->addSql('DROP TABLE escalated_ticket_links');
    }
}
