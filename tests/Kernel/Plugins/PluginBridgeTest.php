<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Plugins;

use Escalated\Symfony\Entity\PluginStoreRecord;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\CrmPlugin;

/**
 * SDK plugins behind the bridge, with a stand-in runtime
 * (tests/Kernel/Fixtures/plugin-runtime.php) that logs every message the host
 * sends it.
 */
final class PluginBridgeTest extends PluginTestCase
{
    private const SLUG = 'acme--sdk-fixture';
    private const NAME = '@acme/sdk-fixture';

    private string $log;

    protected function setUp(): void
    {
        $this->log = sys_get_temp_dir().'/escalated-symfony-plugin-runtime.log';
        @unlink($this->log);
        $this->boot(['plugins' => ['sdk' => [
            'enabled' => true,
            'runtime_command' => [\PHP_BINARY, \dirname(__DIR__).'/Fixtures/plugin-runtime.php', $this->log],
        ]]]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->log);
    }

    public function testSdkPluginsAreListedAsPackageManagedNextToNativeOnes(): void
    {
        $this->loginAdmin();

        $plugins = array_column($this->visit('/support/admin/plugins')['props']['plugins'], null, 'slug');

        self::assertArrayHasKey(CrmPlugin::SLUG, $plugins);
        $sdk = $plugins[self::SLUG];
        self::assertSame(self::NAME, $sdk['name']);
        self::assertSame('0.4.0', $sdk['version']);
        self::assertSame('composer', $sdk['source']);
        self::assertFalse($sdk['is_active']);
        self::assertSame(['menu_items' => 1, 'widgets' => 1, 'page_components' => 0], $sdk['provides']);

        $handshake = $this->sent('handshake')[0];
        self::assertSame(['protocol_version' => '1.0', 'host' => 'symfony'], array_intersect_key($handshake, ['protocol_version' => 1, 'host' => 1]));
    }

    public function testActivationSendsActivateAndThePluginActivatedActionWithTheActivePlugins(): void
    {
        $this->loginAdmin();

        $this->inertia('POST', '/support/admin/plugins/'.self::SLUG.'/activate');
        self::assertResponseRedirects('/support/admin/plugins', 303);
        self::assertTrue($this->row(self::SLUG)?->isActive());

        self::assertSame([['plugin' => self::NAME]], $this->sent('activate'));
        $action = $this->sent('action')[0];
        self::assertSame('plugin.activated', $action['hook']);
        self::assertSame(['plugin' => self::NAME, 'slug' => self::SLUG], $action['event']);
        self::assertSame([self::NAME], $action['plugins']);

        $this->inertia('POST', '/support/admin/plugins/'.self::SLUG.'/deactivate');
        self::assertSame([['plugin' => self::NAME]], $this->sent('deactivate'));
        self::assertFalse($this->row(self::SLUG)?->isActive());
    }

    public function testAPackageManagedPluginCannotBeUninstalledHere(): void
    {
        $this->loginAdmin();
        $this->activate(self::SLUG);

        $this->inertia('DELETE', '/support/admin/plugins/'.self::SLUG);

        self::assertResponseRedirects('/support/admin/plugins', 303);
        self::assertNotEmpty($this->flashes('error'));
        self::assertTrue($this->row(self::SLUG)?->isActive());
    }

    public function testPagesEndpointsAndWebhooksGoThroughTheRuntime(): void
    {
        $this->loginAdmin();
        $this->activate(self::SLUG);

        $page = $this->visit('/support/admin/plugins/'.self::SLUG.'/dash');
        self::assertSame(['plugin' => self::NAME, 'component' => 'SdkDash', 'layout' => 'admin', 'props' => ['widgets' => 3]], $page['props']);

        $this->client->jsonRequest('POST', '/support/api/plugins/'.self::SLUG.'/items/7?full=1', ['qty' => 2], server: [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_AUTHORIZATION' => 'Bearer secret',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('fixture', $this->client->getResponse()->headers->get('x-runtime'));
        $body = $this->json();
        self::assertSame('/items/:id', $body['path']);
        self::assertSame(['id' => '7'], $body['params']);
        self::assertSame(['full' => '1'], $body['query']);
        self::assertSame(['qty' => 2], $body['body']);
        self::assertNotContains('authorization', $body['header_names']);
        self::assertNotContains('cookie', $body['header_names']);
        $sent = $this->sent('endpoint')[1];
        self::assertSame(self::NAME, $sent['plugin']);
        self::assertSame(1, $sent['httpContract']);
        self::assertSame('{"qty":2}', base64_decode($sent['rawBodyBase64'], true));

        $this->client->jsonRequest('POST', '/support/webhooks/plugins/'.self::SLUG.'/hook', ['ping' => true]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('webhook', $this->json()['kind']);
    }

    public function testTheRuntimesCtxCallsAreScopedToThePluginBeingServed(): void
    {
        $admin = $this->loginAdmin();
        $this->activate(self::SLUG);

        $this->client->request('GET', '/support/api/plugins/'.self::SLUG.'/peek');

        self::assertResponseIsSuccessful();
        self::assertSame('from-sdk', $this->json()['stored']);
        self::assertSame($admin->getId(), $this->json()['user']['id']);
        $rows = static::entityManager()->getRepository(PluginStoreRecord::class)->findBy(['collection' => 'notes']);
        self::assertCount(1, $rows);
        self::assertSame(self::NAME, $rows[0]->getPlugin(), 'stored under the plugin being served, not the one it named');
    }

    public function testAnInactiveSdkPluginIsNeverCalled(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/support/api/plugins/'.self::SLUG.'/peek');
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/support/webhooks/plugins/'.self::SLUG.'/hook', []);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/support/admin/plugins/'.self::SLUG.'/dash', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(404);

        $this->loginCustomer();
        $this->inertia('POST', '/support/customer/tickets', ['subject' => 'Quiet', 'description' => 'Nobody listens.']);
        self::assertResponseRedirects();

        self::assertSame([], $this->sent('endpoint'));
        self::assertSame([], $this->sent('webhook'));
        self::assertSame([], $this->sent('action'));
    }

    public function testATicketCreatedOverHttpReachesTheSdkPluginWhichCanWriteBack(): void
    {
        $this->activate(self::SLUG);
        $this->loginCustomer();

        $this->inertia('POST', '/support/customer/tickets', ['subject' => 'Bridge me', 'description' => 'Please.']);
        self::assertResponseRedirects();

        $actions = array_values(array_filter($this->sent('action'), static fn (array $a): bool => 'ticket.created' === $a['hook']));
        self::assertCount(1, $actions);
        self::assertSame('Bridge me', $actions[0]['event']['subject']);
        self::assertSame('Bridge me', $actions[0]['event']['ticket']['title']);
        self::assertSame([self::NAME], $actions[0]['plugins']);
        $seen = static::entityManager()->getRepository(PluginStoreRecord::class)->findBy(['plugin' => self::NAME, 'collection' => 'seen']);
        self::assertCount(1, $seen);
        self::assertSame($actions[0]['event']['id'], $seen[0]->getData()['ticket']);
    }

    public function testFiltersRunNativeAndSdkPluginsByPriority(): void
    {
        $this->activate(CrmPlugin::SLUG);
        $this->activate(self::SLUG);

        self::assertSame('x|sdk|crm', $this->registry()->applyFilter('demo.value', 'x'), 'sdk (15) before crm (20)');
        $filter = $this->sent('filter')[0];
        self::assertSame([self::NAME], $filter['plugins']);
    }

    public function testAThrowingSdkEndpointIsA500WithoutTheError(): void
    {
        $this->loginAdmin();
        $this->activate(self::SLUG);

        $this->client->request('GET', '/support/api/plugins/'.self::SLUG.'/boom');

        self::assertResponseStatusCodeSame(500);
        self::assertStringNotContainsString('kaboom', (string) $this->client->getResponse()->getContent());

        // The runtime is still up for the next call.
        $this->client->request('GET', '/support/api/plugins/'.self::SLUG.'/peek');
        self::assertResponseIsSuccessful();
    }

    /**
     * The params of every message of one method the host sent the runtime.
     *
     * @return list<array<string, mixed>>
     */
    private function sent(string $method): array
    {
        $sent = [];
        foreach (is_file($this->log) ? file($this->log, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] : [] as $line) {
            $message = json_decode($line, true);
            if (\is_array($message) && $message['method'] === $method) {
                $sent[] = $message['params'];
            }
        }

        return $sent;
    }
}
