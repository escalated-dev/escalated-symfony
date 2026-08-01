<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Canned responses — reusable, agent-facing canned replies with a
 * shared/own visibility model. Mirrors the escalated_canned_responses
 * table in escalated-laravel / escalated-rails / escalated-django.
 */
final class Version20260801000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_canned_responses table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_canned_responses (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body LONGTEXT NOT NULL,
            category VARCHAR(255) DEFAULT NULL,
            is_shared TINYINT(1) NOT NULL,
            created_by INT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_canned_response_creator (created_by),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE escalated_canned_responses');
    }
}
