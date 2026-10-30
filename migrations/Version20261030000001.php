<?php

declare(strict_types=1);

namespace Escalated\Symfony\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Escalated\Symfony\Doctrine\Migration\PrefixedMigration;
use Escalated\Symfony\Doctrine\UserIdType;

/**
 * Roles & permissions (ADR 2026-10-29-configurable-access-features), the
 * tables escalated-laravel has: roles, permissions, role_permission and
 * role_user. Fill them with `escalated:permissions:seed`.
 */
final class Version20261030000001 extends PrefixedMigration
{
    public function getDescription(): string
    {
        return 'Add roles, permissions, role_permission and role_user';
    }

    public function up(Schema $schema): void
    {
        $roles = $schema->createTable($this->table('roles'));
        $roles->addColumn('id', 'integer', ['autoincrement' => true]);
        $roles->addColumn('name', 'string', ['length' => 255]);
        $roles->addColumn('slug', 'string', ['length' => 191]);
        $roles->addColumn('description', 'text', ['notnull' => false]);
        $roles->addColumn('is_system', 'boolean', ['default' => false]);
        $roles->addColumn('created_at', 'datetime_immutable');
        $roles->addColumn('updated_at', 'datetime_immutable');
        $roles->setPrimaryKey(['id']);
        $roles->addUniqueIndex(['slug']);

        $permissions = $schema->createTable($this->table('permissions'));
        $permissions->addColumn('id', 'integer', ['autoincrement' => true]);
        $permissions->addColumn('name', 'string', ['length' => 255]);
        $permissions->addColumn('slug', 'string', ['length' => 191]);
        // `group` is reserved on MySQL; backticks make DBAL quote it.
        $permissions->addColumn('`group`', 'string', ['length' => 255]);
        $permissions->addColumn('description', 'text', ['notnull' => false]);
        $permissions->setPrimaryKey(['id']);
        $permissions->addUniqueIndex(['slug']);

        $rolePermission = $schema->createTable($this->table('role_permission'));
        $rolePermission->addColumn('role_id', 'integer');
        $rolePermission->addColumn('permission_id', 'integer');
        $rolePermission->setPrimaryKey(['role_id', 'permission_id']);
        $rolePermission->addForeignKeyConstraint($this->table('roles'), ['role_id'], ['id'], ['onDelete' => 'CASCADE']);
        $rolePermission->addForeignKeyConstraint($this->table('permissions'), ['permission_id'], ['id'], ['onDelete' => 'CASCADE']);

        $roleUser = $schema->createTable($this->table('role_user'));
        $roleUser->addColumn('user_id', UserIdType::NAME);
        $roleUser->addColumn('role_id', 'integer');
        $roleUser->setPrimaryKey(['user_id', 'role_id']);
        $roleUser->addForeignKeyConstraint($this->table('roles'), ['role_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable($this->table('role_user'));
        $schema->dropTable($this->table('role_permission'));
        $schema->dropTable($this->table('permissions'));
        $schema->dropTable($this->table('roles'));
    }
}
