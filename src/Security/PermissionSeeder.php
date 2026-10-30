<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Permission;
use Escalated\Symfony\Entity\Role;

/**
 * Seeds the permissions and the system roles, as escalated-laravel's
 * PermissionSeeder: the same slugs, groups and role definitions.
 *
 * Idempotent: permissions and roles are upserted by slug, and only the system
 * roles' permissions are synced. Custom roles are left alone.
 */
class PermissionSeeder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array{permissions: int, roles: array<string, int>} what was seeded: the permission
     *                                                            count and each system role's
     *                                                            permission count
     */
    public function seed(): array
    {
        $repository = $this->em->getRepository(Permission::class);
        $bySlug = [];
        foreach (self::permissions() as $definition) {
            $permission = $repository->findOneBy(['slug' => $definition['slug']]);
            if (null === $permission) {
                $permission = new Permission($definition['slug'], $definition['name'], $definition['group'], $definition['description']);
                $this->em->persist($permission);
            } else {
                $permission->setName($definition['name'])->setGroup($definition['group'])->setDescription($definition['description']);
            }
            $bySlug[$definition['slug']] = $permission;
        }
        // Permissions added by hand or by an earlier version stay grantable.
        foreach ($repository->findAll() as $permission) {
            $bySlug[$permission->getSlug()] ??= $permission;
        }

        $roles = [];
        foreach (self::roles() as $definition) {
            $role = $this->em->getRepository(Role::class)->findOneBy(['slug' => $definition['slug']]);
            if (null === $role) {
                $role = new Role($definition['name'], $definition['slug']);
                $this->em->persist($role);
            }
            $role->setName($definition['name'])->setDescription($definition['description'])->setIsSystem(true);

            $granted = self::resolve($definition['permissions'], array_keys($bySlug));
            $role->syncPermissions(array_map(static fn (string $slug): Permission => $bySlug[$slug], $granted));
            $roles[$definition['slug']] = \count($granted);
        }

        $this->em->flush();

        return ['permissions' => \count(self::permissions()), 'roles' => $roles];
    }

    /**
     * Exact slugs, `group.*` wildcards, or `*` for every permission.
     *
     * @param list<string> $patterns
     * @param list<string> $slugs
     *
     * @return list<string>
     */
    public static function resolve(array $patterns, array $slugs): array
    {
        $granted = [];
        foreach ($patterns as $pattern) {
            if ('*' === $pattern) {
                return $slugs;
            }
            foreach ($slugs as $slug) {
                if ($slug === $pattern || (str_ends_with($pattern, '.*') && str_starts_with($slug, substr($pattern, 0, -1)))) {
                    $granted[] = $slug;
                }
            }
        }

        return array_values(array_unique($granted));
    }

    /**
     * @return list<array{slug: string, name: string, group: string, description: string}>
     */
    public static function permissions(): array
    {
        $rows = [
            // Tickets
            ['ticket.view', 'View tickets', 'Tickets', 'View tickets'],
            ['ticket.create', 'Create tickets', 'Tickets', 'Create tickets'],
            ['ticket.edit', 'Edit ticket properties', 'Tickets', 'Edit ticket properties'],
            ['ticket.delete', 'Delete tickets', 'Tickets', 'Delete tickets'],
            ['ticket.assign', 'Assign tickets', 'Tickets', 'Assign tickets to agents'],
            ['ticket.merge', 'Merge tickets', 'Tickets', 'Merge tickets together'],
            ['ticket.close', 'Close tickets', 'Tickets', 'Close and reopen tickets'],
            ['ticket.export', 'Export tickets', 'Tickets', 'Export ticket data'],
            // Replies
            ['reply.create', 'Reply to tickets', 'Replies', 'Reply to tickets'],
            ['reply.create_internal', 'Add internal notes', 'Replies', 'Add internal notes'],
            ['reply.edit', 'Edit replies', 'Replies', 'Edit replies'],
            ['reply.delete', 'Delete replies', 'Replies', 'Delete replies'],
            // Knowledge Base
            ['kb.view', 'View knowledge base', 'Knowledge Base', 'View knowledge base'],
            ['kb.create', 'Create articles', 'Knowledge Base', 'Create articles'],
            ['kb.edit', 'Edit articles', 'Knowledge Base', 'Edit articles'],
            ['kb.delete', 'Delete articles', 'Knowledge Base', 'Delete articles'],
            ['kb.publish', 'Publish articles', 'Knowledge Base', 'Publish/unpublish articles'],
            // Departments
            ['department.view', 'View departments', 'Departments', 'View departments'],
            ['department.create', 'Create departments', 'Departments', 'Create departments'],
            ['department.edit', 'Edit departments', 'Departments', 'Edit departments'],
            ['department.delete', 'Delete departments', 'Departments', 'Delete departments'],
            // Reports
            ['report.view', 'View reports', 'Reports', 'View reports and analytics'],
            ['report.export', 'Export reports', 'Reports', 'Export report data'],
            // SLA
            ['sla.view', 'View SLA policies', 'SLA', 'View SLA policies'],
            ['sla.manage', 'Manage SLA policies', 'SLA', 'Create, edit, delete SLA policies'],
            // Automations
            ['automation.view', 'View automations', 'Automations', 'View automations'],
            ['automation.manage', 'Manage automations', 'Automations', 'Create, edit, delete automations'],
            // Escalation Rules
            ['escalation.view', 'View escalation rules', 'Escalation Rules', 'View escalation rules'],
            ['escalation.manage', 'Manage escalation rules', 'Escalation Rules', 'Create, edit, delete escalation rules'],
            // Macros
            ['macro.view', 'View macros', 'Macros', 'View macros'],
            ['macro.create', 'Create macros', 'Macros', 'Create personal macros'],
            ['macro.manage', 'Manage macros', 'Macros', 'Create, edit, delete shared macros'],
            // Tags
            ['tag.view', 'View tags', 'Tags', 'View tags'],
            ['tag.manage', 'Manage tags', 'Tags', 'Create, edit, delete tags'],
            // Custom Fields
            ['custom_field.view', 'View custom fields', 'Custom Fields', 'View custom fields'],
            ['custom_field.manage', 'Manage custom fields', 'Custom Fields', 'Create, edit, delete custom fields'],
            // Roles
            ['role.view', 'View roles', 'Roles', 'View roles'],
            ['role.manage', 'Manage roles', 'Roles', 'Create, edit, delete roles and assign permissions'],
            // Users
            ['user.view', 'View users', 'Users', 'View user profiles'],
            ['user.manage', 'Manage users', 'Users', 'Manage user accounts and agent profiles'],
            // Settings
            ['settings.view', 'View settings', 'Settings', 'View settings'],
            ['settings.manage', 'Manage settings', 'Settings', 'Manage system settings'],
            // Webhooks
            ['webhook.view', 'View webhooks', 'Webhooks', 'View webhooks'],
            ['webhook.manage', 'Manage webhooks', 'Webhooks', 'Create, edit, delete webhooks'],
            // API Tokens
            ['api_token.view', 'View API tokens', 'API Tokens', 'View API tokens'],
            ['api_token.manage', 'Manage API tokens', 'API Tokens', 'Create, revoke API tokens'],
            // Audit Log
            ['audit.view', 'View audit log', 'Audit Log', 'View audit log'],
            // Plugins
            ['plugin.view', 'View plugins', 'Plugins', 'View plugins'],
            ['plugin.manage', 'Manage plugins', 'Plugins', 'Install, configure, remove plugins'],
            // Custom Objects
            ['custom_object.view', 'View custom objects', 'Custom Objects', 'View custom objects'],
            ['custom_object.manage', 'Manage custom objects', 'Custom Objects', 'Create, edit, delete custom object schemas'],
            ['custom_object.data', 'Manage custom object data', 'Custom Objects', 'Manage custom object records'],
        ];

        $permissions = array_map(
            static fn (array $row): array => ['slug' => $row[0], 'name' => $row[1], 'group' => $row[2], 'description' => $row[3]],
            $rows,
        );

        // Newsletters (ADR 2026-05-19-newsletter-system).
        foreach (NewsletterPermissions::seeds() as $slug => $seed) {
            $permissions[] = ['slug' => $slug] + $seed;
        }

        return $permissions;
    }

    /**
     * @return list<array{slug: string, name: string, description: string, permissions: list<string>}>
     */
    public static function roles(): array
    {
        return [
            [
                'slug' => 'admin',
                'name' => 'Admin',
                'description' => 'Full access to all features and settings.',
                'permissions' => ['*'],
            ],
            [
                'slug' => 'agent',
                'name' => 'Agent',
                'description' => 'Standard agent with ticket handling and limited administrative access.',
                'permissions' => ['ticket.*', 'reply.*', 'kb.view', 'report.view', 'macro.view', 'macro.create', 'tag.view', 'custom_field.view', 'audit.view'],
            ],
            [
                'slug' => 'light_agent',
                'name' => 'Light Agent',
                'description' => 'Limited agent with read-only ticket access and internal note capability.',
                'permissions' => ['ticket.view', 'reply.create', 'reply.create_internal', 'kb.view', 'macro.view', 'tag.view'],
            ],
        ];
    }
}
