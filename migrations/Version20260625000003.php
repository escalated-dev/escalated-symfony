<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-agent, per-channel concurrent-ticket capacity. Mirrors the Laravel
 * agent_capacity table: a configurable ceiling (max_concurrent) and a
 * running load (current_count), unique per (user_id, channel).
 */
final class Version20260625000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_agent_capacity table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_agent_capacity (
            id INT AUTO_INCREMENT NOT NULL,
            user_id VARCHAR(255) NOT NULL,
            channel VARCHAR(64) NOT NULL,
            max_concurrent INT DEFAULT 10 NOT NULL,
            current_count INT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX escalated_agent_capacity_user_channel_unique (user_id, channel),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE escalated_agent_capacity');
    }
}
