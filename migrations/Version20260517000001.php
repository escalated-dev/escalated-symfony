<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Escalated\Symfony\Doctrine\Migration\BundleMigration;

final class Version20260517000001 extends BundleMigration
{
    public function getDescription(): string
    {
        return 'Create escalated_skills, escalated_skill_routing_tags, escalated_skill_routing_departments, escalated_agent_skills';
    }

    public function up(Schema $schema): void
    {
        if ($this->executedUnderLegacyName()) {
            return;
        }

        // Built as standalone tables and emitted through the platform instead
        // of on $schema, so the proficiency CHECK can follow the CREATE TABLE:
        // Doctrine Migrations runs addSql() statements before the schema diff.
        $skills = new Table('escalated_skills');
        $skills->addColumn('id', 'integer', ['autoincrement' => true]);
        $skills->addColumn('name', 'string', ['length' => 100]);
        $skills->addColumn('slug', 'string', ['length' => 100]);
        $skills->addColumn('description', 'text', ['notnull' => false]);
        $skills->addColumn('created_at', 'datetime_immutable');
        $skills->addColumn('updated_at', 'datetime_immutable');
        $skills->setPrimaryKey(['id']);
        $skills->addUniqueIndex(['slug'], 'UNIQ_escalated_skills_slug');
        $skills->addUniqueIndex(['name'], 'UNIQ_escalated_skills_name');

        $routingTags = new Table('escalated_skill_routing_tags');
        $routingTags->addColumn('id', 'integer', ['autoincrement' => true]);
        $routingTags->addColumn('skill_id', 'integer');
        $routingTags->addColumn('tag_id', 'integer');
        $routingTags->setPrimaryKey(['id']);
        $routingTags->addIndex(['skill_id'], 'IDX_skill_routing_tag_skill');
        $routingTags->addIndex(['tag_id'], 'IDX_skill_routing_tag_tag');
        $routingTags->addUniqueIndex(['skill_id', 'tag_id'], 'UNIQ_skill_routing_tag');
        $routingTags->addForeignKeyConstraint('escalated_skills', ['skill_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_skill_routing_tag_skill');
        $routingTags->addForeignKeyConstraint('escalated_tags', ['tag_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_skill_routing_tag_tag');

        $routingDepartments = new Table('escalated_skill_routing_departments');
        $routingDepartments->addColumn('id', 'integer', ['autoincrement' => true]);
        $routingDepartments->addColumn('skill_id', 'integer');
        $routingDepartments->addColumn('department_id', 'integer');
        $routingDepartments->setPrimaryKey(['id']);
        $routingDepartments->addIndex(['skill_id'], 'IDX_skill_routing_dept_skill');
        $routingDepartments->addIndex(['department_id'], 'IDX_skill_routing_dept_dept');
        $routingDepartments->addUniqueIndex(['skill_id', 'department_id'], 'UNIQ_skill_routing_dept');
        $routingDepartments->addForeignKeyConstraint('escalated_skills', ['skill_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_skill_routing_dept_skill');
        $routingDepartments->addForeignKeyConstraint('escalated_departments', ['department_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_skill_routing_dept_dept');

        $agentSkills = new Table('escalated_agent_skills');
        $agentSkills->addColumn('id', 'integer', ['autoincrement' => true]);
        $agentSkills->addColumn('user_id', 'integer');
        $agentSkills->addColumn('skill_id', 'integer');
        $agentSkills->addColumn('proficiency', 'smallint', ['default' => 3]);
        $agentSkills->addColumn('created_at', 'datetime_immutable');
        $agentSkills->addColumn('updated_at', 'datetime_immutable');
        $agentSkills->setPrimaryKey(['id']);
        $agentSkills->addIndex(['skill_id'], 'IDX_agent_skills_skill');
        $agentSkills->addIndex(['user_id'], 'IDX_agent_skills_user');
        $agentSkills->addUniqueIndex(['user_id', 'skill_id'], 'UNIQ_agent_skills_user_skill');
        $agentSkills->addForeignKeyConstraint('escalated_skills', ['skill_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_agent_skills_skill');

        foreach ($this->platform->getCreateTablesSQL([$skills, $routingTags, $routingDepartments, $agentSkills]) as $sql) {
            $this->addSql($sql);
        }

        // SQLite only accepts CHECK inside CREATE TABLE; the entity validates
        // the range on every platform.
        if ($this->platform instanceof AbstractMySQLPlatform || $this->platform instanceof PostgreSQLPlatform) {
            $this->addSql('ALTER TABLE escalated_agent_skills ADD CONSTRAINT CHK_agent_skills_proficiency CHECK (proficiency >= 1 AND proficiency <= 5)');
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('escalated_agent_skills');
        $schema->dropTable('escalated_skill_routing_departments');
        $schema->dropTable('escalated_skill_routing_tags');
        $schema->dropTable('escalated_skills');
    }
}
