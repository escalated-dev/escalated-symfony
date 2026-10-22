<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;
use Escalated\Symfony\Doctrine\UserIdType;

/**
 * The import framework's tables, as escalated-laravel's: import_jobs (status,
 * sealed credentials, mappings, progress, error log) and import_source_maps
 * (which Escalated record each source record became).
 */
final class Version20261025000101 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add import_jobs and import_source_maps';
    }

    public function up(Schema $schema): void
    {
        $jobs = $schema->createTable($this->table('import_jobs'));
        $jobs->addColumn('id', 'string', ['length' => 36]);
        $jobs->addColumn('user_id', UserIdType::NAME, ['notnull' => false]);
        $jobs->addColumn('platform', 'string', ['length' => 50]);
        $jobs->addColumn('status', 'string', ['length' => 30, 'default' => 'pending']);
        $jobs->addColumn('credentials', 'text', ['notnull' => false]);
        $jobs->addColumn('field_mappings', 'json', ['notnull' => false]);
        $jobs->addColumn('progress', 'json', ['notnull' => false]);
        $jobs->addColumn('error_log', 'json', ['notnull' => false]);
        $jobs->addColumn('started_at', 'datetime_immutable', ['notnull' => false]);
        $jobs->addColumn('completed_at', 'datetime_immutable', ['notnull' => false]);
        $jobs->addColumn('created_at', 'datetime_immutable');
        $jobs->addColumn('updated_at', 'datetime_immutable');
        $jobs->setPrimaryKey(['id']);
        $jobs->addIndex(['status'], 'idx_import_job_status');
        $jobs->addIndex(['user_id'], 'idx_import_job_user');

        $maps = $schema->createTable($this->table('import_source_maps'));
        $maps->addColumn('id', 'integer', ['autoincrement' => true]);
        $maps->addColumn('import_job_id', 'string', ['length' => 36]);
        $maps->addColumn('entity_type', 'string', ['length' => 50]);
        $maps->addColumn('source_id', 'string', ['length' => 191]);
        $maps->addColumn('escalated_id', 'string', ['length' => 191]);
        $maps->addColumn('created_at', 'datetime_immutable');
        $maps->setPrimaryKey(['id']);
        $maps->addUniqueIndex(['import_job_id', 'entity_type', 'source_id'], 'uniq_import_source_map');
        $maps->addIndex(['import_job_id', 'entity_type'], 'idx_import_source_map_type');
        $maps->addForeignKeyConstraint($this->table('import_jobs'), ['import_job_id'], ['id'], ['onDelete' => 'CASCADE'], $this->generatedName('fk', 'import_source_maps', ['import_job_id']));
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->table('import_source_maps'));
        $schema->dropTable($this->table('import_jobs'));
    }
}
