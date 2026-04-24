<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260424000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_settings key/value table for runtime-mutable settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_settings (
            `key` VARCHAR(191) NOT NULL,
            value LONGTEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(`key`)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE escalated_settings');
    }
}
