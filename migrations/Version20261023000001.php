<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

final class Version20261023000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add custom_fields.placeholder, validation_rules and conditions for the custom field admin';
    }

    public function up(Schema $schema): void
    {
        $fields = $schema->getTable($this->table('custom_fields'));
        $fields->addColumn('placeholder', 'string', ['length' => 255, 'notnull' => false]);
        $fields->addColumn('validation_rules', 'json', ['notnull' => false]);
        $fields->addColumn('conditions', 'json', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $fields = $schema->getTable($this->table('custom_fields'));
        $fields->dropColumn('conditions');
        $fields->dropColumn('validation_rules');
        $fields->dropColumn('placeholder');
    }
}
