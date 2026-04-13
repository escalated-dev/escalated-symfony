<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260413000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_attachments table for ticket and reply file attachments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_attachments (
            id INT AUTO_INCREMENT NOT NULL,
            ticket_id INT DEFAULT NULL,
            reply_id INT DEFAULT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            mime_type VARCHAR(127) DEFAULT NULL,
            size INT NOT NULL,
            disk VARCHAR(32) NOT NULL DEFAULT \'local\',
            path VARCHAR(512) NOT NULL DEFAULT \'\',
            url VARCHAR(1024) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_attachment_ticket (ticket_id),
            INDEX idx_attachment_reply (reply_id),
            PRIMARY KEY(id),
            CONSTRAINT fk_attachment_ticket FOREIGN KEY (ticket_id) REFERENCES escalated_tickets (id) ON DELETE CASCADE,
            CONSTRAINT fk_attachment_reply FOREIGN KEY (reply_id) REFERENCES escalated_replies (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE escalated_attachments');
    }
}
