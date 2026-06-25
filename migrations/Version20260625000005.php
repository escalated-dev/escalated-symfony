<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Side conversations and their replies — private internal/email threads
 * attached to a ticket. Mirrors the Laravel side_conversations and
 * side_conversation_replies tables.
 */
final class Version20260625000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_side_conversations + escalated_side_conversation_replies tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_side_conversations (
            id INT AUTO_INCREMENT NOT NULL,
            ticket_id INT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            channel VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL,
            created_by VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_side_conversation_ticket (ticket_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE escalated_side_conversation_replies (
            id INT AUTO_INCREMENT NOT NULL,
            side_conversation_id INT NOT NULL,
            body LONGTEXT NOT NULL,
            author_id VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_side_conversation_reply_conversation (side_conversation_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE escalated_side_conversations
            ADD CONSTRAINT FK_escalated_side_conversations_ticket
            FOREIGN KEY (ticket_id) REFERENCES escalated_tickets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE escalated_side_conversation_replies
            ADD CONSTRAINT FK_escalated_side_conversation_replies_conversation
            FOREIGN KEY (side_conversation_id) REFERENCES escalated_side_conversations (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalated_side_conversation_replies DROP FOREIGN KEY FK_escalated_side_conversation_replies_conversation');
        $this->addSql('ALTER TABLE escalated_side_conversations DROP FOREIGN KEY FK_escalated_side_conversations_ticket');
        $this->addSql('DROP TABLE escalated_side_conversation_replies');
        $this->addSql('DROP TABLE escalated_side_conversations');
    }
}
