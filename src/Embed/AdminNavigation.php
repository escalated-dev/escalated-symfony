<?php

declare(strict_types=1);

namespace Escalated\Symfony\Embed;

use Escalated\Symfony\Plugin\PluginRegistry;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\EnsureAdminVoter;
use Escalated\Symfony\Security\RoutePermissions;
use Escalated\Symfony\Security\UserPermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Escalated's admin menu as data, so a host can list it in its own sidebar
 * (ADR 2026-11-05-embeddable-admin). Shared as `escalated.navigation` while
 * the admin is embedded, and available to Twig layouts as
 * `escalated_admin_navigation()` and to PHP as adminNavigation().
 *
 * Filtered server-side so a host sidebar never links to a screen that would
 * answer 403 or 404: Escalated's admin gate (unless the host replaced it),
 * routes that are not registered, feature switches and, while roles &
 * permissions is on, each route's permission. Active plugins' admin menu
 * items are merged in, and everything is sorted by position.
 */
final class AdminNavigation
{
    /**
     * Core items: key (also the icon key), label, route name, position.
     * Labels and positions match the standalone admin sidebar in the shared
     * frontend; route names are this bundle's (`api_tokens`, `settings.index`).
     */
    public const ITEMS = [
        ['reports', 'Reports', 'escalated.admin.reports', 10],
        ['tickets', 'Tickets', 'escalated.admin.tickets.index', 20],
        ['departments', 'Departments', 'escalated.admin.departments.index', 30],
        ['sla-policies', 'SLA Policies', 'escalated.admin.sla-policies.index', 40],
        ['knowledge-base', 'Knowledge Base', 'escalated.admin.kb-articles.index', 45],
        ['escalation-rules', 'Escalation Rules', 'escalated.admin.escalation-rules.index', 50],
        ['workflows', 'Workflows', 'escalated.admin.workflows.index', 53],
        ['automations', 'Automations', 'escalated.admin.automations.index', 53.5],
        ['webhooks', 'Webhooks', 'escalated.admin.webhooks.index', 54],
        ['tags', 'Tags', 'escalated.admin.tags.index', 60],
        ['custom-fields', 'Custom Fields', 'escalated.admin.custom-fields.index', 62],
        ['statuses', 'Statuses', 'escalated.admin.statuses.index', 64],
        ['canned-responses', 'Canned Responses', 'escalated.admin.canned-responses.index', 70],
        ['macros', 'Macros', 'escalated.admin.macros.index', 80],
        ['newsletters', 'Newsletters', 'escalated.admin.newsletters.index', 81],
        ['skills', 'Skills', 'escalated.admin.skills.index', 82],
        ['users', 'Users', 'escalated.admin.users.index', 83],
        ['roles', 'Roles', 'escalated.admin.roles.index', 84],
        ['capacity', 'Capacity', 'escalated.admin.capacity.index', 85],
        ['business-hours', 'Business Hours', 'escalated.admin.business-hours.index', 86],
        ['audit-log', 'Audit Log', 'escalated.admin.audit-log', 88],
        ['api-tokens', 'API Tokens', 'escalated.admin.api_tokens.index', 90],
        ['custom-objects', 'Custom Objects', 'escalated.admin.custom-objects.index', 92],
        ['plugins', 'Plugins', 'escalated.admin.plugins.index', 95],
        ['import', 'Import', 'escalated.admin.import.index', 97],
        ['settings', 'Settings', 'escalated.admin.settings.index', 100],
    ];

    public function __construct(
        private readonly EmbedConfig $embed,
        private readonly UrlGeneratorInterface $urls,
        private readonly RequestStack $requests,
        private readonly Security $security,
        private readonly AccessFeatures $features,
        private readonly UserPermissions $permissions,
        private readonly ?PluginRegistry $plugins = null,
    ) {
    }

    /**
     * The admin menu for `$user` (default: the signed-in user) on `$request`
     * (default: the current request), which decides the `active` item.
     * Works whether or not embedding is on; the URLs follow wherever the
     * admin routes are.
     *
     * @return list<array{key: string, label: string, route: ?string, url: string, icon: ?string,
     *                     position: int|float, active: bool, badge: mixed, plugin: ?string, children: list<array<string, mixed>>}>
     */
    public function adminNavigation(?UserInterface $user = null, ?Request $request = null): array
    {
        $user ??= $this->security->getUser();
        if (!$user instanceof UserInterface) {
            return [];
        }

        if (!$this->embed->replacesAdminGate() && !EnsureAdminVoter::hasAdminRole($user)) {
            return [];
        }

        $request ??= $this->requests->getCurrentRequest();
        $path = null === $request ? null : $request->getBaseUrl().$request->getPathInfo();
        $rolesPermissions = $this->features->rolesPermissions();
        $granted = $rolesPermissions ? $this->grantedTo($user) : null;

        $items = [];
        foreach (self::ITEMS as [$key, $label, $route, $position]) {
            if ('roles' === $key && !$rolesPermissions) {
                continue;
            }
            if (null !== $granted) {
                $required = RoutePermissions::requiredFor($route);
                if (null === $required || [] !== array_diff($required, $granted)) {
                    continue;
                }
            }

            try {
                $url = $this->urls->generate($route);
            } catch (RoutingException) {
                // Not registered: the feature is off.
                continue;
            }

            $items[] = self::item($key, $label, $route, $url, $key, $position, $path);
        }

        foreach ($this->pluginItems() as $index => $plugin) {
            $url = $plugin['route'] ?? null;
            if (!\is_string($url) || '' === $url) {
                continue;
            }
            $slug = \is_string($plugin['plugin'] ?? null) ? $plugin['plugin'] : null;
            $item = self::item(
                'plugin:'.($slug ?? 'native').':'.$index,
                (string) ($plugin['label'] ?? ''),
                null,
                $url,
                \is_string($plugin['icon'] ?? null) ? $plugin['icon'] : null,
                is_numeric($plugin['position'] ?? null) ? $plugin['position'] + 0 : 100,
                $path,
            );
            $item['plugin'] = $slug;
            $items[] = $item;
        }

        // Stable sort by position, as the standalone sidebar orders them.
        $order = array_keys($items);
        array_multisort(array_column($items, 'position'), \SORT_ASC, \SORT_NUMERIC, $order, \SORT_ASC, $items);

        return array_values($items);
    }

    /**
     * @return array{key: string, label: string, route: ?string, url: string, icon: ?string,
     *               position: int|float, active: bool, badge: mixed, plugin: ?string, children: list<array<string, mixed>>}
     */
    private static function item(string $key, string $label, ?string $route, string $url, ?string $icon, int|float $position, ?string $path): array
    {
        $target = parse_url($url, \PHP_URL_PATH);
        $target = \is_string($target) && '' !== $target ? $target : $url;

        return [
            'key' => $key,
            'label' => $label,
            'route' => $route,
            'url' => $url,
            'icon' => $icon,
            'position' => $position,
            'active' => null !== $path && ($path === $target || str_starts_with($path, rtrim($target, '/').'/')),
            'badge' => null,
            'plugin' => null,
            'children' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function grantedTo(UserInterface $user): array
    {
        try {
            return $this->permissions->of($user);
        } catch (\Throwable) {
            // The roles tables may not exist yet.
            return [];
        }
    }

    /**
     * Active plugins' admin menu items, already filtered by the plugin
     * contract (admins only, the page's capability).
     *
     * @return list<array<string, mixed>>
     */
    private function pluginItems(): array
    {
        try {
            $items = $this->plugins?->uiExtensions()['menuItems'] ?? [];
        } catch (\Throwable) {
            // The plugin tables may not exist yet.
            return [];
        }

        return array_values(array_filter($items, static fn (mixed $item): bool => \is_array($item) && 'admin' === ($item['section'] ?? 'admin')));
    }
}
