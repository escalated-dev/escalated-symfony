<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260424000002 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_settings key/value table for runtime-mutable settings';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        $settings = $schema->createTable('escalated_settings');
        // `key` is reserved on MySQL; backticks make DBAL quote it for the
        // platform, matching the entity's mapping.
        $settings->addColumn('`key`', 'string', ['length' => 191]);
        $settings->addColumn('value', 'text', ['notnull' => false]);
        $settings->addColumn('updated_at', 'datetime_immutable');
        $settings->setPrimaryKey(['`key`']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_settings');
    }
}
