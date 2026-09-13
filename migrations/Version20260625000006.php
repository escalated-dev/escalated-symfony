<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260625000006 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_article_categories + escalated_articles tables';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $categories = $schema->createTable('escalated_article_categories');
        $categories->addColumn('id', 'integer', ['autoincrement' => true]);
        $categories->addColumn('name', 'string', ['length' => 255]);
        $categories->addColumn('slug', 'string', ['length' => 255]);
        $categories->addColumn('parent_id', 'integer', ['notnull' => false]);
        $categories->addColumn('position', 'integer', ['default' => 0]);
        $categories->addColumn('description', 'text', ['notnull' => false]);
        $categories->addColumn('created_at', 'datetime_immutable');
        $categories->addColumn('updated_at', 'datetime_immutable');
        $categories->setPrimaryKey(['id']);
        $categories->addUniqueIndex(['slug'], 'escalated_article_categories_slug_unique');
        $categories->addIndex(['parent_id'], 'idx_article_category_parent');
        $categories->addForeignKeyConstraint('escalated_article_categories', ['parent_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_escalated_article_categories_parent');

        $articles = $schema->createTable('escalated_articles');
        $articles->addColumn('id', 'integer', ['autoincrement' => true]);
        $articles->addColumn('category_id', 'integer', ['notnull' => false]);
        $articles->addColumn('title', 'string', ['length' => 255]);
        $articles->addColumn('slug', 'string', ['length' => 255]);
        $articles->addColumn('body', 'text', ['notnull' => false]);
        $articles->addColumn('status', 'string', ['length' => 32]);
        $articles->addColumn('author_id', 'string', ['length' => 255, 'notnull' => false]);
        $articles->addColumn('view_count', 'integer', ['default' => 0]);
        $articles->addColumn('helpful_count', 'integer', ['default' => 0]);
        $articles->addColumn('not_helpful_count', 'integer', ['default' => 0]);
        $articles->addColumn('published_at', 'datetime_immutable', ['notnull' => false]);
        $articles->addColumn('created_at', 'datetime_immutable');
        $articles->addColumn('updated_at', 'datetime_immutable');
        $articles->setPrimaryKey(['id']);
        $articles->addUniqueIndex(['slug'], 'escalated_articles_slug_unique');
        $articles->addIndex(['status'], 'idx_article_status');
        $articles->addIndex(['category_id'], 'idx_article_category');
        $articles->addForeignKeyConstraint('escalated_article_categories', ['category_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_escalated_articles_category');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_articles');
        $schema->dropTable('escalated_article_categories');
    }
}
