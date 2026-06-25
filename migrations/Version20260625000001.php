<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Escalation rules — admin-configured, time-based rules that escalate /
 * reprioritise / (re)assign / move open tickets. Mirrors the Laravel
 * escalation_rules table.
 */
final class Version20260625000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_escalation_rules table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_escalation_rules (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description LONGTEXT DEFAULT NULL,
            trigger_type VARCHAR(255) DEFAULT NULL,
            conditions JSON NOT NULL COMMENT \'(DC2Type:json)\',
            actions JSON NOT NULL COMMENT \'(DC2Type:json)\',
            sort_order INT NOT NULL,
            is_active TINYINT(1) NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_escalated_escalation_rules_active (is_active),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE escalated_escalation_rules');
    }
}
