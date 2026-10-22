<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;

final class Version20261022000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add business_schedule_id to sla_policies and departments for business-hours SLA deadlines';
    }

    public function up(Schema $schema): void
    {
        foreach (['sla_policies' => 'idx_sla_business_schedule', 'departments' => 'idx_dept_business_schedule'] as $table => $index) {
            $t = $schema->getTable($this->table($table));
            $t->addColumn('business_schedule_id', 'integer', ['notnull' => false]);
            $t->addIndex(['business_schedule_id'], $index);
            $t->addForeignKeyConstraint($this->table('business_schedules'), ['business_schedule_id'], ['id'], ['onDelete' => 'SET NULL'], $this->generatedName('fk', $table, ['business_schedule_id']));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['sla_policies' => 'idx_sla_business_schedule', 'departments' => 'idx_dept_business_schedule'] as $table => $index) {
            $t = $schema->getTable($this->table($table));
            $t->dropForeignKey($this->generatedName('fk', $table, ['business_schedule_id']));
            $t->dropIndex($index);
            $t->dropColumn('business_schedule_id');
        }
    }
}
