<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260801000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_canned_responses table';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $responses = $schema->createTable('escalated_canned_responses');
        $responses->addColumn('id', 'integer', ['autoincrement' => true]);
        $responses->addColumn('title', 'string', ['length' => 255]);
        $responses->addColumn('body', 'text');
        $responses->addColumn('category', 'string', ['length' => 255, 'notnull' => false]);
        $responses->addColumn('is_shared', 'boolean');
        $responses->addColumn('created_by', 'integer', ['notnull' => false]);
        $responses->addColumn('created_at', 'datetime_immutable');
        $responses->addColumn('updated_at', 'datetime_immutable');
        $responses->setPrimaryKey(['id']);
        $responses->addIndex(['created_by'], 'idx_canned_response_creator');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_canned_responses');
    }
}
