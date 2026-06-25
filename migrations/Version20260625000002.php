<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Satisfaction (CSAT) ratings — one 1-5 score per resolved/closed ticket,
 * with an optional comment and an optional polymorphic "rated by" pointer.
 * Mirrors the Laravel satisfaction_ratings table.
 */
final class Version20260625000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_satisfaction_ratings table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_satisfaction_ratings (
            id INT AUTO_INCREMENT NOT NULL,
            ticket_id INT NOT NULL,
            rating SMALLINT NOT NULL,
            comment LONGTEXT DEFAULT NULL,
            rated_by_type VARCHAR(255) DEFAULT NULL,
            rated_by_id VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX escalated_satisfaction_rating_ticket_unique (ticket_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE escalated_satisfaction_ratings
            ADD CONSTRAINT FK_escalated_satisfaction_ratings_ticket
            FOREIGN KEY (ticket_id) REFERENCES escalated_tickets (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalated_satisfaction_ratings DROP FOREIGN KEY FK_escalated_satisfaction_ratings_ticket');
        $this->addSql('DROP TABLE escalated_satisfaction_ratings');
    }
}
