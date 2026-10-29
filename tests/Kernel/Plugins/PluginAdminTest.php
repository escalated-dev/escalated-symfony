<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Plugins;

use Escalated\Symfony\Entity\Plugin;
use Escalated\Symfony\Entity\PluginStoreRecord;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\AuditPlugin;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\CrmPlugin;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\PluginRecorder;
use Symfony\Component\Routing\RouterInterface;

/**
 * Plugin contract checklist, "Admin screen": Escalated/Admin/Plugins/Index,
 * activate, deactivate and uninstall, and the lifecycle order.
 */
final class PluginAdminTest extends PluginTestCase
{
    private const INDEX = '/support/admin/plugins';

    protected function setUp(): void
    {
        $this->boot();
    }

    public function testTheRoutesThePageCallsExist(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $routes = $router->getRouteCollection();

        foreach (['index' => ['GET', '/support/admin/plugins'], 'activate' => ['POST', '/support/admin/plugins/{slug}/activate'], 'deactivate' => ['POST', '/support/admin/plugins/{slug}/deactivate'], 'destroy' => ['DELETE', '/support/admin/plugins/{slug}']] as $name => [$method, $path]) {
            $route = $routes->get('escalated.admin.plugins.'.$name);
            self::assertNotNull($route, $name);
            self::assertSame($path, $route->getPath());
            self::assertSame([$method], $route->getMethods());
        }
        self::assertNull($routes->get('escalated.admin.plugins.upload'));
    }

    public function testAnAdminSeesEveryRegisteredPlugin(): void
    {
        $this->loginAdmin();

        $page = $this->visit(self::INDEX);

        self::assertSame('Escalated/Admin/Plugins/Index', $page['component']);
        self::assertFalse($page['props']['can_upload']);
        $plugins = array_column($page['props']['plugins'], null, 'slug');
        self::assertSame([AuditPlugin::SLUG, CrmPlugin::SLUG], array_keys($plugins));

        $crm = $plugins[CrmPlugin::SLUG];
        foreach (['slug', 'name', 'description', 'version', 'author', 'author_url', 'homepage', 'is_active', 'activated_at', 'source', 'provides'] as $key) {
            self::assertArrayHasKey($key, $crm);
        }
        self::assertSame(CrmPlugin::NAME, $crm['name']);
        self::assertSame('1.2.0', $crm['version']);
        self::assertSame('Acme', $crm['author']);
        self::assertSame('https://acme.example/crm', $crm['homepage']);
        self::assertSame('https://acme.example/crm', $crm['author_url']);
        self::assertFalse($crm['is_active']);
        self::assertNull($crm['activated_at']);
        self::assertSame('registered', $crm['source']);
        self::assertSame(['menu_items' => 3, 'widgets' => 1, 'page_components' => 2], $crm['provides']);
        self::assertNull($plugins[AuditPlugin::SLUG]['description']);
    }

    public function testAgentsCustomersAndGuestsAreRefused(): void
    {
        $this->client->request('GET', self::INDEX, server: ['HTTP_X_INERTIA' => 'true']);
        $this->assertRefused();

        $this->loginCustomer();
        $this->client->request('GET', self::INDEX, server: ['HTTP_X_INERTIA' => 'true']);
        $this->assertRefused();
        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/activate');
        $this->assertRefused();

        $this->loginAgent();
        $this->client->request('GET', self::INDEX, server: ['HTTP_X_INERTIA' => 'true']);
        $this->assertRefused();
        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/activate');
        $this->assertRefused();
        $this->inertia('DELETE', self::INDEX.'/'.CrmPlugin::SLUG);
        $this->assertRefused();

        self::assertNull($this->row(CrmPlugin::SLUG));
    }

    public function testActivatingAndDeactivatingIsIdempotent(): void
    {
        $this->loginAdmin();

        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/activate');
        self::assertResponseRedirects(self::INDEX, 303);
        self::assertContains('Plugin activated.', $this->flashes('success'));
        $row = $this->row(CrmPlugin::SLUG);
        self::assertNotNull($row);
        self::assertTrue($row->isActive());
        self::assertNotNull($row->getActivatedAt());
        self::assertNull($row->getDeactivatedAt());
        $activatedAt = $row->getActivatedAt();

        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/activate');
        self::assertResponseRedirects(self::INDEX, 303);
        self::assertEquals($activatedAt, $this->row(CrmPlugin::SLUG)?->getActivatedAt(), 'activating an active plugin changes nothing');
        self::assertSame(['onActivate'], $this->calls(CrmPlugin::NAME, 'onActivate'));

        $page = $this->visit(self::INDEX);
        $crm = array_column($page['props']['plugins'], null, 'slug')[CrmPlugin::SLUG];
        self::assertTrue($crm['is_active']);
        self::assertNotNull($crm['activated_at']);

        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/deactivate');
        self::assertResponseRedirects(self::INDEX, 303);
        self::assertContains('Plugin deactivated.', $this->flashes('success'));
        $row = $this->row(CrmPlugin::SLUG);
        self::assertFalse($row?->isActive());
        self::assertNotNull($row?->getDeactivatedAt());

        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/deactivate');
        self::assertResponseRedirects(self::INDEX, 303);
        self::assertSame(['onDeactivate'], $this->calls(CrmPlugin::NAME, 'onDeactivate'));

        // A plugin that never had a row deactivates as a no-op success.
        $this->inertia('POST', self::INDEX.'/'.AuditPlugin::SLUG.'/deactivate');
        self::assertResponseRedirects(self::INDEX, 303);
        self::assertNull($this->row(AuditPlugin::SLUG));
    }

    public function testAnUnknownSlugIsAnErrorFlashOrA404AndCreatesNoRow(): void
    {
        $this->loginAdmin();

        foreach (['POST' => '/nope/activate', 'DELETE' => '/nope'] as $method => $path) {
            $this->inertia($method, self::INDEX.$path);
            self::assertResponseRedirects(self::INDEX, 303);
            self::assertContains('Plugin not found.', $this->flashes('error'));

            $this->client->request($method, self::INDEX.$path, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(404);
        }
        $this->client->request('POST', self::INDEX.'/nope/deactivate', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(404);

        self::assertSame(0, static::entityManager()->getRepository(Plugin::class)->count([]));
    }

    public function testUninstallingARegisteredPluginForgetsItsActivationButKeepsItsData(): void
    {
        $this->loginAdmin();
        $this->activate(CrmPlugin::SLUG);
        $em = static::entityManager();
        $em->persist(new PluginStoreRecord(CrmPlugin::NAME, 'contacts', 'c1', ['name' => 'Ada']));
        $em->flush();

        $this->inertia('DELETE', self::INDEX.'/'.CrmPlugin::SLUG);

        self::assertResponseRedirects(self::INDEX, 303);
        self::assertContains('Plugin uninstalled.', $this->flashes('success'));
        self::assertNull($this->row(CrmPlugin::SLUG));
        $listed = array_column($this->visit(self::INDEX)['props']['plugins'], null, 'slug');
        self::assertArrayHasKey(CrmPlugin::SLUG, $listed, 'a registered plugin stays listed: its code is in the build');
        self::assertFalse($listed[CrmPlugin::SLUG]['is_active']);
        self::assertSame(1, static::entityManager()->getRepository(PluginStoreRecord::class)->count(['plugin' => CrmPlugin::NAME]));

        // Reinstalling finds its data again.
        $this->activate(CrmPlugin::SLUG);
        $crm = $this->registry()->find(CrmPlugin::SLUG);
        self::assertNotNull($crm);
        self::assertSame(['Ada'], array_column($this->registry()->context($crm)->storeQuery('contacts'), 'name'));
    }

    public function testThereIsNoUploadRoute(): void
    {
        $this->loginAdmin();

        $this->client->request('POST', self::INDEX.'/upload', server: ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheLifecycleRunsInTheDocumentedOrderAndReachesEveryActivePlugin(): void
    {
        $this->loginAdmin();
        $this->activate(AuditPlugin::SLUG);

        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/activate');
        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/deactivate');
        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/activate');
        $this->inertia('DELETE', self::INDEX.'/'.CrmPlugin::SLUG);

        self::assertSame([
            // activate: row, onActivate, plugin.activated to every active plugin, the subject included
            CrmPlugin::NAME.' onActivate',
            AuditPlugin::NAME.' action plugin.activated',
            CrmPlugin::NAME.' action plugin.activated',
            // deactivate: plugin.deactivated while still active, then onDeactivate
            AuditPlugin::NAME.' action plugin.deactivated',
            CrmPlugin::NAME.' action plugin.deactivated',
            CrmPlugin::NAME.' onDeactivate',
            CrmPlugin::NAME.' onActivate',
            AuditPlugin::NAME.' action plugin.activated',
            CrmPlugin::NAME.' action plugin.activated',
            // uninstall: plugin.uninstalling, then deactivation
            AuditPlugin::NAME.' action plugin.uninstalling',
            CrmPlugin::NAME.' action plugin.uninstalling',
            AuditPlugin::NAME.' action plugin.deactivated',
            CrmPlugin::NAME.' action plugin.deactivated',
            CrmPlugin::NAME.' onDeactivate',
        ], PluginRecorder::trail());

        self::assertSame(['plugin' => CrmPlugin::NAME, 'slug' => CrmPlugin::SLUG], PluginRecorder::events(AuditPlugin::NAME, 'plugin.activated')[0]);
    }

    public function testAThrowingCallbackDoesNotUndoTheTransition(): void
    {
        $this->loginAdmin();
        PluginRecorder::$throwOn = [CrmPlugin::NAME.':onActivate', CrmPlugin::NAME.':onDeactivate', CrmPlugin::NAME.':plugin.activated'];

        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/activate');
        self::assertResponseRedirects(self::INDEX, 303);
        self::assertContains('Plugin activated.', $this->flashes('success'));
        self::assertTrue($this->row(CrmPlugin::SLUG)?->isActive());

        $this->inertia('POST', self::INDEX.'/'.CrmPlugin::SLUG.'/deactivate');
        self::assertResponseRedirects(self::INDEX, 303);
        self::assertFalse($this->row(CrmPlugin::SLUG)?->isActive());
    }

    private function assertRefused(): void
    {
        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [401, 403], 'expected a refusal, got '.$status);
        self::assertStringNotContainsString('Escalated/Admin/Plugins/Index', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return list<string>
     */
    private function calls(string $plugin, string $call): array
    {
        return array_values(array_map(
            static fn (array $c): string => $c['call'],
            array_filter(PluginRecorder::$calls, static fn (array $c): bool => $c['plugin'] === $plugin && $c['call'] === $call),
        ));
    }
}
