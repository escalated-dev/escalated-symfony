<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Outbound webhooks — admin-registered HTTP endpoints that receive a signed
 * POST for every subscribed domain event, plus a per-attempt delivery log.
 * Mirrors the escalated_webhooks / escalated_webhook_deliveries tables in
 * escalated-laravel / escalated-rails / escalated-django.
 */
final class Version20260731000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_webhooks and escalated_webhook_deliveries tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_webhooks (
            id INT AUTO_INCREMENT NOT NULL,
            url VARCHAR(500) NOT NULL,
            events JSON NOT NULL COMMENT \'(DC2Type:json)\',
            secret VARCHAR(255) DEFAULT NULL,
            active TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE escalated_webhook_deliveries (
            id INT AUTO_INCREMENT NOT NULL,
            webhook_id INT NOT NULL,
            event VARCHAR(255) NOT NULL,
            payload JSON DEFAULT NULL COMMENT \'(DC2Type:json)\',
            response_code SMALLINT DEFAULT NULL,
            response_body LONGTEXT DEFAULT NULL,
            attempts SMALLINT NOT NULL,
            delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX idx_webhook_delivery_webhook_event (webhook_id, event),
            PRIMARY KEY(id),
            CONSTRAINT FK_webhook_delivery_webhook FOREIGN KEY (webhook_id) REFERENCES escalated_webhooks (id) ON DELETE CASCADE
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE escalated_webhook_deliveries');
        $this->addSql('DROP TABLE escalated_webhooks');
    }
}
