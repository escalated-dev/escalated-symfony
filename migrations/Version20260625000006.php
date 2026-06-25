<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Knowledge-base tables: article categories (optionally nested) and
 * articles (draft/published, with view and helpfulness counters). Mirrors
 * the Laravel article_categories and articles tables.
 */
final class Version20260625000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_article_categories + escalated_articles tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE escalated_article_categories (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            parent_id INT DEFAULT NULL,
            position INT DEFAULT 0 NOT NULL,
            description LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX escalated_article_categories_slug_unique (slug),
            INDEX idx_article_category_parent (parent_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('CREATE TABLE escalated_articles (
            id INT AUTO_INCREMENT NOT NULL,
            category_id INT DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            body LONGTEXT DEFAULT NULL,
            status VARCHAR(32) NOT NULL,
            author_id VARCHAR(255) DEFAULT NULL,
            view_count INT DEFAULT 0 NOT NULL,
            helpful_count INT DEFAULT 0 NOT NULL,
            not_helpful_count INT DEFAULT 0 NOT NULL,
            published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            UNIQUE INDEX escalated_articles_slug_unique (slug),
            INDEX idx_article_status (status),
            INDEX idx_article_category (category_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE escalated_article_categories
            ADD CONSTRAINT FK_escalated_article_categories_parent
            FOREIGN KEY (parent_id) REFERENCES escalated_article_categories (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE escalated_articles
            ADD CONSTRAINT FK_escalated_articles_category
            FOREIGN KEY (category_id) REFERENCES escalated_article_categories (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE escalated_articles DROP FOREIGN KEY FK_escalated_articles_category');
        $this->addSql('ALTER TABLE escalated_article_categories DROP FOREIGN KEY FK_escalated_article_categories_parent');
        $this->addSql('DROP TABLE escalated_articles');
        $this->addSql('DROP TABLE escalated_article_categories');
    }
}
