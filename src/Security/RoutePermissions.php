<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

/**
 * The permission each admin, agent and staff API route requires while roles &
 * permissions is enforced (ADR 2026-10-29-configurable-access-features), the
 * port of escalated-laravel's Support\RoutePermissions.
 *
 * Keys are route names or `*` patterns (`*` matches anything, dots included);
 * the first match wins, so exact names come before patterns. A route that
 * matches nothing is refused by RoutePermissionSubscriber, and a test
 * enumerates the router so a new route cannot ship without an entry here.
 *
 * Every slug is one PermissionSeeder seeds; none are invented here.
 */
final class RoutePermissions
{
    /** Statuses whose change needs ticket.close as well as ticket.edit. */
    public const CLOSING_STATUSES = ['resolved', 'closed', 'reopened'];

    /** Bulk actions => the permission each needs. */
    public const BULK_ACTIONS = [
        'status' => ['ticket.edit'],
        'priority' => ['ticket.edit'],
        'assign' => ['ticket.assign'],
        'tags' => ['ticket.edit'],
        'department' => ['ticket.edit'],
        'delete' => ['ticket.delete'],
    ];

    /**
     * API routes that are not staff routes: the mobile guest API (a guest
     * holds a grant, never a principal) and the public knowledge base.
     */
    private const NOT_STAFF = ['escalated.api.mobile.guest.*', 'escalated.api.kb.*'];

    /**
     * Whether the route is an admin, agent or staff API route, which the map
     * must cover and which the permission check applies to.
     */
    public static function governs(string $routeName): bool
    {
        if (!str_starts_with($routeName, 'escalated.admin.')
            && !str_starts_with($routeName, 'escalated.agent.')
            && !str_starts_with($routeName, 'escalated.api.')) {
            return false;
        }

        foreach (self::NOT_STAFF as $pattern) {
            if (self::matches($pattern, $routeName)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Route name => required permission slugs. An empty list means "staff,
     * no particular permission" and is reserved for personal endpoints.
     *
     * @return array<string, list<string>>
     */
    public static function map(): array
    {
        return [
            // ---- Personal ------------------------------------------------
            'escalated.api.auth.me' => [],

            // ---- Tickets (admin and agent panels, staff API) --------------
            'escalated.agent.dashboard' => ['ticket.view'],
            'escalated.agent.canned_responses.index' => ['ticket.view'],
            'escalated.agent.macros.index' => ['macro.view'],
            'escalated.agent.macros.apply' => ['macro.view', 'ticket.edit'],
            'escalated.agent.views.*' => ['ticket.view'],

            'escalated.*.tickets.index' => ['ticket.view'],
            'escalated.*.tickets.show' => ['ticket.view'],
            'escalated.admin.tickets.merge-search' => ['ticket.view'],
            'escalated.*.tickets.follow' => ['ticket.view'],
            'escalated.*.tickets.presence' => ['ticket.view'],
            'escalated.admin.ticket_links.index' => ['ticket.view'],
            'escalated.admin.side_conversations.index' => ['ticket.view'],

            'escalated.api.tickets.store' => ['ticket.create'],
            'escalated.agent.tickets.split' => ['ticket.create'],
            'escalated.*.tickets.update' => ['ticket.edit'],
            'escalated.*.tickets.status' => ['ticket.edit'],
            'escalated.*.tickets.priority' => ['ticket.edit'],
            'escalated.*.tickets.tags' => ['ticket.edit'],
            'escalated.*.tickets.department' => ['ticket.edit'],
            'escalated.*.tickets.pin' => ['ticket.edit'],
            'escalated.*.tickets.custom-action' => ['ticket.edit'],
            'escalated.*.tickets.snooze' => ['ticket.edit'],
            'escalated.*.tickets.unsnooze' => ['ticket.edit'],
            'escalated.*.tickets.subjects.*' => ['ticket.edit'],
            'escalated.api.tickets.rating' => ['ticket.edit'],
            'escalated.admin.ticket_links.*' => ['ticket.edit'],
            'escalated.*.tickets.assign' => ['ticket.assign'],
            'escalated.admin.tickets.merge' => ['ticket.merge'],
            'escalated.admin.tickets.delete' => ['ticket.delete'],
            'escalated.*.tickets.macro' => ['macro.view', 'ticket.edit'],
            // Bulk: per action, see requiredFor().
            'escalated.*.tickets.bulk' => ['ticket.view'],

            'escalated.*.tickets.reply' => ['reply.create'],
            'escalated.*.tickets.note' => ['reply.create_internal'],
            'escalated.admin.side_conversations.*' => ['reply.create'],

            // ---- Live chat -------------------------------------------------
            'escalated.agent.chat.sessions' => ['ticket.view'],
            'escalated.agent.chat.*' => ['reply.create'],
            'escalated.admin.chat.*' => ['reply.create'],

            // ---- Reports -----------------------------------------------------
            'escalated.admin.reports.export' => ['report.export'],
            'escalated.admin.reports' => ['report.view'],
            'escalated.admin.reports.*' => ['report.view'],

            // ---- Knowledge base ----------------------------------------------
            ...self::both('kb-articles', 'kb_articles', [
                'index' => ['kb.view'],
                'create' => ['kb.create'],
                'store' => ['kb.create'],
                'edit' => ['kb.edit'],
                'update' => ['kb.edit'],
                'destroy' => ['kb.delete'],
                'delete' => ['kb.delete'],
            ]),
            ...self::both('kb-categories', 'kb_categories', [
                'index' => ['kb.view'],
                'store' => ['kb.edit'],
                'update' => ['kb.edit'],
                'destroy' => ['kb.delete'],
                'delete' => ['kb.delete'],
            ]),

            // ---- Departments ---------------------------------------------------
            'escalated.admin.departments.index' => ['department.view'],
            'escalated.admin.departments.create' => ['department.create'],
            'escalated.admin.departments.store' => ['department.create'],
            'escalated.admin.departments.edit' => ['department.edit'],
            'escalated.admin.departments.update' => ['department.edit'],
            'escalated.admin.departments.destroy' => ['department.delete'],
            'escalated.admin.departments.delete' => ['department.delete'],

            // ---- Admin resources: index => *.view, everything else => *.manage
            ...self::resource('sla-policies', 'sla'),
            ...self::resource('business-hours', 'sla'),
            ...self::resource('escalation-rules', 'escalation'),
            ...self::resource('escalation_rules', 'escalation'),
            ...self::resource('automations', 'automation'),
            ...self::resource('workflows', 'automation', ['logs']),
            ...self::resource('tags', 'tag'),
            ...self::resource('custom-fields', 'custom_field'),
            ...self::resource('statuses', 'settings'),
            ...self::resource('roles', 'role'),
            ...self::resource('skills', 'user'),
            ...self::resource('capacity', 'user'),
            ...self::resource('webhooks', 'webhook', ['deliveries']),
            ...self::resource('api_tokens', 'api_token'),
            ...self::resource('plugins', 'plugin'),
            ...self::resource('canned-responses', 'macro'),
            ...self::resource('canned_responses', 'macro'),

            // Macros: anyone who may create one may save it; shared edits manage.
            'escalated.admin.macros.index' => ['macro.view'],
            'escalated.admin.macros.store' => ['macro.create'],
            'escalated.admin.macros.*' => ['macro.manage'],

            // Custom objects: schemas manage, records data.
            'escalated.admin.custom-objects.records' => ['custom_object.view'],
            'escalated.admin.custom-objects.records.*' => ['custom_object.data'],
            ...self::resource('custom-objects', 'custom_object'),

            // Users
            'escalated.admin.users.index' => ['user.view'],
            'escalated.admin.users.role' => ['user.manage'],
            'escalated.admin.users.roles' => ['role.manage'],

            'escalated.admin.audit-log' => ['audit.view'],

            // ---- Settings ------------------------------------------------------
            'escalated.admin.settings.index' => ['settings.view'],
            'escalated.admin.settings.update' => ['settings.manage'],
            'escalated.admin.settings.database.test' => ['settings.manage'],
            'escalated.admin.settings.*.update' => ['settings.manage'],
            'escalated.admin.settings.*' => ['settings.view'],
            'escalated.admin.import.*' => ['settings.manage'],

            // ---- Newsletters (ADR 2026-05-19-newsletter-system) ----------------
            // Sending also needs newsletters.send, checked by NewsletterPermissionVoter.
            'escalated.admin.newsletters.*' => ['newsletters.manage'],
        ];
    }

    public static function isMapped(string $routeName): bool
    {
        return null !== self::lookup($routeName);
    }

    /**
     * The slugs a request to the named route needs, or null when the route is
     * not mapped (the caller refuses it).
     *
     * @param array<string, mixed> $payload the request body
     *
     * @return list<string>|null
     */
    public static function requiredFor(string $routeName, array $payload = []): ?array
    {
        $required = self::lookup($routeName);

        if (null === $required) {
            return null;
        }

        if (self::matches('escalated.*.tickets.bulk', $routeName)) {
            $action = \is_string($payload['action'] ?? null) ? $payload['action'] : '';
            $required = [...$required, ...(self::BULK_ACTIONS[$action] ?? [])];
            if ('status' === $action) {
                $required = [...$required, ...self::closing($payload['value'] ?? null)];
            }
        } elseif (\array_key_exists('status', $payload) && \in_array('ticket.edit', $required, true)) {
            $required = [...$required, ...self::closing($payload['status'])];
        }

        return array_values(array_unique($required));
    }

    /**
     * @return list<string>|null
     */
    private static function lookup(string $routeName): ?array
    {
        foreach (self::map() as $pattern => $slugs) {
            if (self::matches($pattern, $routeName)) {
                return $slugs;
            }
        }

        return null;
    }

    /**
     * Laravel's Str::is: `*` matches any run of characters.
     */
    public static function matches(string $pattern, string $routeName): bool
    {
        if ($pattern === $routeName) {
            return true;
        }

        $regex = '#\A'.str_replace('\*', '.*', preg_quote($pattern, '#')).'\z#u';

        return 1 === preg_match($regex, $routeName);
    }

    /**
     * @return list<string>
     */
    private static function closing(mixed $status): array
    {
        return \is_string($status) && \in_array($status, self::CLOSING_STATUSES, true) ? ['ticket.close'] : [];
    }

    /**
     * index (and any extra read actions) => {group}.view; anything else under
     * the resource => {group}.manage.
     *
     * @param list<string> $extraReads
     *
     * @return array<string, list<string>>
     */
    private static function resource(string $name, string $group, array $extraReads = []): array
    {
        $entries = ["escalated.admin.{$name}.index" => ["{$group}.view"]];

        foreach ($extraReads as $read) {
            $entries["escalated.admin.{$name}.{$read}"] = ["{$group}.view"];
        }

        $entries["escalated.admin.{$name}.*"] = ["{$group}.manage"];

        return $entries;
    }

    /**
     * The same entries under a route group's two spellings (the shared
     * pages use the dashed names; older hosts link the underscored ones).
     *
     * @param array<string, list<string>> $actions
     *
     * @return array<string, list<string>>
     */
    private static function both(string $dashed, string $underscored, array $actions): array
    {
        $entries = [];
        foreach ([$dashed, $underscored] as $name) {
            foreach ($actions as $action => $slugs) {
                $entries["escalated.admin.{$name}.{$action}"] = $slugs;
            }
        }

        return $entries;
    }
}
