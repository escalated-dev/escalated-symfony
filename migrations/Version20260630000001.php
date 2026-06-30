<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ticket followers — host users who follow a ticket and are a notification
 * target alongside the assignee and requester. See issue #67.
 */
final class Version20260630000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_ticket_followers table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_ticket_followers (
            id INT AUTO_INCREMENT NOT NULL,
            ticket_id INT NOT NULL,
            user_id VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX UNIQ_ticket_followers_ticket_user (ticket_id, user_id),
            INDEX idx_ticket_follower_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE escalated_ticket_followers');
    }
}
