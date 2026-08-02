<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API tokens — hashed, per-user bearer credentials for programmatic access to
 * the REST API. Only the SHA-256 hash of a token is stored; the plaintext is
 * shown once at creation. Mirrors the escalated_api_tokens table in
 * escalated-laravel (abilities, last_used_at, expires_at).
 */
final class Version20260802000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_api_tokens table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_api_tokens (
            id INT AUTO_INCREMENT NOT NULL,
            user_id VARCHAR(255) NOT NULL,
            name VARCHAR(255) NOT NULL,
            token VARCHAR(64) NOT NULL,
            abilities JSON DEFAULT NULL COMMENT \'(DC2Type:json)\',
            last_used_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            last_used_ip VARCHAR(45) DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX uniq_escalated_api_token (token),
            INDEX idx_api_token_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE escalated_api_tokens');
    }
}
