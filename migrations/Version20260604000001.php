<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add next_attempt_at to newsletter deliveries so the dispatcher schedules retry
 * backoff (1m / 5m / 30m) instead of relying on attempt_count alone — bringing the
 * Symfony port in line with the other Escalated backends' send engine.
 */
final class Version20260604000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add next_attempt_at retry-backoff column to escalated_newsletter_deliveries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalated_newsletter_deliveries ADD next_attempt_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_escalated_nl_deliveries_claim ON escalated_newsletter_deliveries (status, next_attempt_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_escalated_nl_deliveries_claim ON escalated_newsletter_deliveries');
        $this->addSql('ALTER TABLE escalated_newsletter_deliveries DROP next_attempt_at');
    }
}
