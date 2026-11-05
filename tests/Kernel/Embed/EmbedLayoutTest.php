<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Embed;

use Escalated\Symfony\Embed\AdminNavigation;
use Escalated\Symfony\Plugin\PluginRegistry;
use Escalated\Symfony\Tests\Kernel\Fixtures\Embed\HostHeadersSubscriber;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\CrmPlugin;
use Symfony\Component\Routing\RouterInterface;

/**
 * Layout mode (host types A and B of ADR 2026-11-05-embeddable-admin): the
 * admin moves under the host's prefix and guards, keeps its route names and
 * Escalated's own gates, and the pages carry `escalated.embed` and
 * `escalated.navigation`.
 */
final class EmbedLayoutTest extends EmbedTestCase
{
    private const CORE_KEYS = [
        'reports', 'tickets', 'departments', 'sla-policies', 'knowledge-base', 'escalation-rules', 'workflows',
        'automations', 'webhooks', 'tags', 'custom-fields', 'statuses', 'canned-responses', 'macros', 'skills',
        'users', 'capacity', 'business-hours', 'audit-log', 'api-tokens', 'custom-objects', 'plugins', 'import',
        'settings',
    ];

    public function testTheAdminAnswersUnderTheHostPrefixBehindTheHostGuard(): void
    {
        $this->boot(self::layoutEmbed());

        $this->client->loginUser($this->admin);
        $page = $this->visit('/admin/support/tags');
        self::assertSame('Escalated/Admin/Tags/Index', $page['component']);
        self::assertResponseHeaderSame('X-Host-Guard', 'granted');

        // One name, one URL: the standalone admin is gone.
        self::assertSame(404, $this->statusOf('/support/admin/tags'));
        self::assertSame(404, $this->statusOf('/support/admin/reports'));

        // The host guard refuses an Escalated admin the host does not admit.
        $this->client->loginUser($this->escalatedAdmin);
        self::assertSame(403, $this->statusOf('/admin/support/tags'));
        self::assertResponseHeaderSame('X-Host-Guard', 'denied');
    }

    public function testTheHostGuardRefusesVisitorsWhoAreNotSignedIn(): void
    {
        $this->boot(self::layoutEmbed());

        $status = $this->statusOf('/admin/support/tags');
        self::assertContains($status, [401, 403]);
        self::assertResponseHeaderSame('X-Host-Guard', 'denied');
    }

    public function testEscalatedsAdminGateStaysOnBehindTheHostGuard(): void
    {
        $this->boot(self::layoutEmbed());

        // Admitted by the host, refused by Escalated: not an Escalated admin.
        $this->client->loginUser($this->hostAdmin);
        self::assertSame(403, $this->statusOf('/admin/support/tags'));
        self::assertResponseHeaderSame('X-Host-Guard', 'granted');
        self::assertSame(403, $this->statusOf('/admin/support/settings'));
        self::assertSame([], $this->navigationOf($this->hostAdmin));
    }

    public function testEveryAdminRouteMovesWithItsNameAndNothingElseChanges(): void
    {
        $this->boot(self::layoutEmbed(), ['enable_newsletters' => true]);
        $router = static::getContainer()->get('router');
        \assert($router instanceof RouterInterface);
        $live = RouteTable::normalised(RouteTable::of($router));
        $snapshot = RouteTable::snapshot()['newsletters'];

        self::assertSame(array_column($snapshot, 'name'), array_column($live, 'name'), 'same routes, same order');

        $moved = 0;
        foreach ($snapshot as $index => $before) {
            $after = $live[$index];
            $name = $before['name'];
            $standaloneAdmin = (str_starts_with($name, 'escalated.admin.') || 'escalated.plugin.page' === $name)
                && str_starts_with($before['path'], '/support/admin');
            $stays = str_starts_with($name, 'escalated.admin.two-factor.') || str_starts_with($name, 'escalated.admin.newsletters.');

            if ($standaloneAdmin && !$stays) {
                ++$moved;
                $expected = $before;
                $expected['path'] = '/admin/support'.substr($before['path'], \strlen('/support/admin'));
                $expected['defaults']['_escalated_embedded'] = true;
                ksort($expected['defaults']);
                self::assertSame($expected, $after, $name.' moves under the host prefix, otherwise unchanged');
            } else {
                self::assertSame($before, $after, $name.' is not touched');
            }
        }
        self::assertGreaterThan(150, $moved);

        // Spot checks: what stays where it was.
        foreach ([
            'escalated.admin.two-factor.index' => '/support/admin/settings/two-factor',
            'escalated.admin.newsletters.index' => '/support/admin/newsletters',
            'escalated.agent.dashboard' => '/support/agent/dashboard',
            'escalated.customer.tickets.index' => '/support/customer/tickets',
            'escalated.plugins.endpoint' => '/support/api/plugins/{slug}/{path}',
            'escalated.admin.api_tokens.index' => '/admin/support/api-tokens',
            'escalated.plugin.page' => '/admin/support/plugins/{slug}/{route}',
            'escalated.admin.settings.sso' => '/admin/support/settings/sso',
        ] as $name => $path) {
            self::assertSame($path, $router->getRouteCollection()->get($name)?->getPath(), $name);
        }
        self::assertSame('/admin/support/reports', $router->generate('escalated.admin.reports'));
    }

    public function testUnmovedPagesKeepTheirUrlsAndGates(): void
    {
        $this->boot(self::layoutEmbed(), ['enable_newsletters' => true]);

        $this->client->loginUser($this->admin);
        self::assertSame('Escalated/Agent/Dashboard', $this->visit('/support/agent/dashboard')['component']);
        self::assertSame('Escalated/Admin/Newsletters/Index', $this->visit('/support/admin/newsletters')['component']);
        self::assertSame(404, $this->statusOf('/admin/support/newsletters'));

        // The agent panel keeps Escalated's agent gate, whatever the host says.
        $this->client->loginUser($this->hostAdmin);
        self::assertSame(403, $this->statusOf('/support/agent/dashboard'));
    }

    public function testEmbeddedPagesCarryTheEmbedPropAndOtherPagesSayTheyAreNotEmbedded(): void
    {
        $this->boot(self::layoutEmbed());
        $this->client->loginUser($this->admin);

        $this->visit('/admin/support/tags');
        self::assertSame([
            'enabled' => true,
            'mode' => 'layout',
            'layout' => 'Layouts/AdminLayout',
            'route' => 'escalated.admin.tags.index',
            'base_url' => '/admin/support',
            'allowed_origins' => [],
        ], $this->shared()['embed']);

        $this->visit('/admin/support/departments/create');
        self::assertSame('escalated.admin.departments.create', $this->shared()['embed']['route']);

        $this->visit('/support/agent/dashboard');
        $embed = $this->shared()['embed'];
        self::assertFalse($embed['enabled']);
        self::assertNull($embed['route']);
        self::assertSame('/admin/support', $embed['base_url']);
        self::assertNotEmpty($this->shared()['navigation'], 'the menu is shared on every page');
    }

    public function testLayoutModeLeavesTheFramingHeadersAlone(): void
    {
        $this->boot(self::layoutEmbed());
        $this->client->loginUser($this->admin);

        $this->visit('/admin/support/tags');
        $headers = $this->client->getResponse()->headers;
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame([HostHeadersSubscriber::CSP], $headers->all('content-security-policy'));
    }

    public function testTheNavigationHasTheAdrShapeOrderAndActiveItem(): void
    {
        $this->boot(self::layoutEmbed());
        $this->client->loginUser($this->admin);

        $this->visit('/admin/support/tags');
        $navigation = $this->shared()['navigation'];

        self::assertSame(self::CORE_KEYS, array_column($navigation, 'key'), 'roles are off; newsletters are off');
        self::assertSame([
            'key' => 'tags',
            'label' => 'Tags',
            'route' => 'escalated.admin.tags.index',
            'url' => '/admin/support/tags',
            'icon' => 'tags',
            'position' => 60,
            'active' => true,
            'badge' => null,
            'plugin' => null,
            'children' => [],
        ], $navigation[array_search('tags', array_column($navigation, 'key'), true)]);
        self::assertSame(['tags'], array_column(array_filter($navigation, static fn (array $i): bool => $i['active']), 'key'));

        $byKey = array_column($navigation, null, 'key');
        self::assertSame('/admin/support/reports', $byKey['reports']['url']);
        self::assertSame('escalated.admin.api_tokens.index', $byKey['api-tokens']['route']);
        self::assertSame('/admin/support/api-tokens', $byKey['api-tokens']['url']);
        self::assertSame('escalated.admin.settings.index', $byKey['settings']['route']);
        self::assertSame(53.5, $byKey['automations']['position']);
        $positions = array_column($navigation, 'position');
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);

        // A page below an item's URL makes it active.
        $this->visit('/admin/support/departments/create');
        self::assertSame(['departments'], array_column(array_filter($this->shared()['navigation'], static fn (array $i): bool => $i['active']), 'key'));

        // The same list for templates and controllers.
        $helper = static::getContainer()->get(AdminNavigation::class);
        \assert($helper instanceof AdminNavigation);
        self::assertSame(array_column($navigation, 'url'), array_column($helper->adminNavigation($this->admin), 'url'));

        // Not an Escalated admin: no menu.
        $this->client->loginUser($this->agent);
        $this->visit('/support/agent/dashboard');
        self::assertSame([], $this->shared()['navigation']);
    }

    public function testTheNavigationFollowsFeatureSwitchesAndRoutePermissions(): void
    {
        $this->boot(self::layoutEmbed(), ['enable_newsletters' => true, 'access' => ['roles_permissions' => true]], seedPermissions: true);
        $this->giveAllPermissionsExcept($this->admin, ['settings.view', 'tag.view']);
        $this->client->loginUser($this->admin);

        $this->visit('/admin/support/reports');
        $byKey = array_column($this->shared()['navigation'], null, 'key');

        self::assertArrayHasKey('roles', $byKey, 'roles with roles & permissions on');
        self::assertSame('/admin/support/roles', $byKey['roles']['url']);
        self::assertArrayHasKey('newsletters', $byKey, 'newsletters while they are on');
        self::assertSame('/support/admin/newsletters', $byKey['newsletters']['url'], 'the newsletter admin does not move');
        self::assertArrayNotHasKey('settings', $byKey, 'no settings.view');
        self::assertArrayNotHasKey('tags', $byKey, 'no tag.view');
        self::assertTrue($byKey['reports']['active']);

        // Route permissions still guard the embedded routes.
        self::assertSame(403, $this->statusOf('/admin/support/tags'));
        self::assertSame(403, $this->statusOf('/admin/support/settings'));
    }

    public function testPluginAdminItemsAreMergedByPositionAndTheirPagesMove(): void
    {
        $this->boot(self::layoutEmbed());
        $registry = static::getContainer()->get(PluginRegistry::class);
        \assert($registry instanceof PluginRegistry);
        self::assertTrue($registry->activate(CrmPlugin::SLUG));
        $this->client->loginUser($this->admin);

        $this->visit('/admin/support/reports');
        $navigation = $this->shared()['navigation'];
        self::assertSame([
            'key' => 'plugin:'.CrmPlugin::SLUG.':0',
            'label' => 'CRM',
            'route' => null,
            'url' => '/admin/support/plugins/'.CrmPlugin::SLUG.'/settings',
            'icon' => 'link',
            'position' => 5,
            'active' => false,
            'badge' => null,
            'plugin' => CrmPlugin::SLUG,
            'children' => [],
        ], $navigation[0], 'position 5 sorts before Reports (10)');
        self::assertNotContains('Secret', array_column($navigation, 'label'), 'the page needs a capability the admin lacks');
        self::assertNotContains('CRM lookups', array_column($navigation, 'label'), 'agent section items are not admin menu items');
        self::assertSame('/admin/support/plugins/'.CrmPlugin::SLUG.'/settings', $this->shared()['plugins']['menuItems'][0]['route']);

        $page = $this->visit('/admin/support/plugins/'.CrmPlugin::SLUG.'/settings');
        self::assertSame('Escalated/Plugin/Page', $page['component']);
        self::assertTrue($this->shared()['navigation'][0]['active']);
        self::assertSame(404, $this->statusOf('/support/admin/plugins/'.CrmPlugin::SLUG.'/settings'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function navigationOf(object $user): array
    {
        $helper = static::getContainer()->get(AdminNavigation::class);
        \assert($helper instanceof AdminNavigation);
        \assert($user instanceof \Symfony\Component\Security\Core\User\UserInterface);

        return $helper->adminNavigation($user);
    }
}
