<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Plugins;

use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\AuditPlugin;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\CrmPlugin;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\PluginRecorder;

/**
 * Plugin contract checklist, "Pages, endpoints, webhooks".
 */
final class PluginPagesAndEndpointsTest extends PluginTestCase
{
    private const PAGE = '/support/admin/plugins/'.CrmPlugin::SLUG;
    private const API = '/support/api/plugins/'.CrmPlugin::SLUG;
    private const HOOKS = '/support/webhooks/plugins/'.CrmPlugin::SLUG;

    protected function setUp(): void
    {
        $this->boot();
    }

    public function testAnActivePluginsPageRendersWithThePropsOfItsComponentEndpoint(): void
    {
        $admin = $this->loginAdmin();
        $this->activate(CrmPlugin::SLUG);

        $page = $this->visit(self::PAGE.'/settings');

        self::assertSame('Escalated/Plugin/Page', $page['component']);
        self::assertSame(CrmPlugin::NAME, $page['props']['plugin']);
        self::assertSame('CrmSettings', $page['props']['component']);
        self::assertSame('admin', $page['props']['layout']);
        self::assertSame('set', $page['props']['props']['api_key']);
        self::assertSame($admin->getId(), $page['props']['props']['user']['id'], 'ctx.currentUser is the signed-in user');

        // A page without a GET /{component} endpoint gets {} as props.
        $agentPage = $this->visit(self::PAGE.'/agent-view');
        self::assertSame('agent', $agentPage['props']['layout']);
        self::assertSame([], $agentPage['props']['props']);
        self::assertSame('{}', json_encode(json_decode((string) $this->client->getResponse()->getContent())->props->props));
    }

    public function testAPageWhosePropsEndpointThrowsStillRendersWithEmptyProps(): void
    {
        $this->loginAdmin();
        $this->activate(CrmPlugin::SLUG);
        PluginRecorder::$throwOn = [CrmPlugin::NAME.':GET /CrmSettings'];

        $page = $this->visit(self::PAGE.'/settings');

        self::assertSame('CrmSettings', $page['props']['component']);
        self::assertSame([], $page['props']['props']);
    }

    public function testAnInactivePluginsPagesAndUnknownPagesAre404(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', self::PAGE.'/settings', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(404);

        $this->activate(CrmPlugin::SLUG);
        $this->client->request('GET', self::PAGE.'/nothing-here', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/support/admin/plugins/acme--unknown/settings', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminsAreRefusedThePage(): void
    {
        $this->activate(CrmPlugin::SLUG);

        $this->client->request('GET', self::PAGE.'/settings', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);

        $this->loginAgent();
        $this->client->request('GET', self::PAGE.'/settings', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('Escalated/Plugin/Page', (string) $this->client->getResponse()->getContent());
    }

    public function testAPageOrEndpointWithACapabilityTheUserLacksIs403(): void
    {
        $this->loginAdmin();
        $this->activate(CrmPlugin::SLUG);

        $this->client->request('GET', self::PAGE.'/secret', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', self::API.'/secret');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], PluginRecorder::trail(), 'the handler never ran');
    }

    public function testAPageOrEndpointWithACapabilityTheUserHoldsIsServed(): void
    {
        $this->loginAdmin([CrmPlugin::SECRET_ROLE]);
        $this->activate(CrmPlugin::SLUG);

        self::assertSame('CrmSecret', $this->visit(self::PAGE.'/secret')['props']['component']);
        $this->client->request('GET', self::API.'/secret');
        self::assertResponseIsSuccessful();
    }

    public function testAnEndpointGetsItsParamsQueryAndBodyButNoCredentials(): void
    {
        $this->loginAdmin();
        $this->activate(CrmPlugin::SLUG);

        $this->client->request('GET', self::API.'/contacts/42?expand=notes', server: [
            'HTTP_AUTHORIZATION' => 'Bearer secret-token',
            'HTTP_COOKIE' => 'other=1',
            'HTTP_X_CSRF_TOKEN' => 'csrf',
            'HTTP_X_XSRF_TOKEN' => 'xsrf',
            'HTTP_PROXY_AUTHORIZATION' => 'Basic proxy',
            'HTTP_X_TRACE' => 'trace-1',
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('GET /contacts/:id', $body['handled'], 'dispatched on the manifest path');
        self::assertSame(['id' => '42'], $body['params']);
        self::assertSame(['expand' => 'notes'], $body['query']);
        self::assertSame('trace-1', $body['headers']['x-trace']);
        foreach (['cookie', 'authorization', 'x-csrf-token', 'x-xsrf-token', 'proxy-authorization'] as $header) {
            self::assertArrayNotHasKey($header, $body['headers']);
        }
        self::assertStringNotContainsString('secret-token', (string) $this->client->getResponse()->getContent());

        $this->client->jsonRequest('POST', self::API.'/contacts/7', ['name' => 'Ada'], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('POST /contacts/:id', $body['handled']);
        self::assertSame(['id' => '7'], $body['params']);
        self::assertSame(['name' => 'Ada'], $body['body']);
        $request = PluginRecorder::$calls[array_key_last(PluginRecorder::$calls)]['request'];
        self::assertSame(1, $request['httpContract']);
        self::assertSame('{"name":"Ada"}', base64_decode($request['rawBodyBase64'], true));
    }

    public function testAStateChangingEndpointCallMustComeFromAPageScript(): void
    {
        $this->loginAdmin();
        $this->activate(CrmPlugin::SLUG);

        $this->client->request('POST', self::API.'/contacts/7', ['name' => 'Ada']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], PluginRecorder::trail());
    }

    public function testAnEnvelopeSetsTheStatusFormatAndHeaders(): void
    {
        $this->loginAdmin();
        $this->activate(CrmPlugin::SLUG);

        $this->client->request('GET', self::API.'/teapot');

        self::assertResponseStatusCodeSame(418);
        self::assertSame('short and stout', $this->client->getResponse()->getContent());
        self::assertSame('crm', $this->client->getResponse()->headers->get('x-plugin'));
    }

    public function testEndpointsAreForAdminsAndActivePluginsOnly(): void
    {
        $this->client->request('GET', self::API.'/contacts/1');
        self::assertContains($this->client->getResponse()->getStatusCode(), [401, 403]);

        $this->loginAgent();
        $this->client->request('GET', self::API.'/contacts/1');
        self::assertResponseStatusCodeSame(403);

        $this->loginAdmin();
        $this->client->request('GET', self::API.'/contacts/1');
        self::assertResponseStatusCodeSame(404, 'inactive plugin');

        $this->activate(CrmPlugin::SLUG);
        $this->client->request('GET', self::API.'/not-declared');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('DELETE', self::API.'/contacts/1', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(404, 'undeclared method');
        $this->client->request('GET', '/support/api/plugins/'.AuditPlugin::SLUG.'/contacts/1');
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], PluginRecorder::trail());
    }

    public function testAThrowingEndpointIsA500WithoutTheError(): void
    {
        $this->loginAdmin();
        $this->activate(CrmPlugin::SLUG);

        $this->client->request('GET', self::API.'/boom');

        self::assertResponseStatusCodeSame(500);
        self::assertStringNotContainsString('secret failure detail', (string) $this->client->getResponse()->getContent());
    }

    public function testAWebhookAnswersWithoutAuthOnlyForAnActivePlugin(): void
    {
        $this->client->jsonRequest('POST', self::HOOKS.'/inbound', ['event' => 'contact.updated']);
        self::assertResponseStatusCodeSame(404, 'inactive plugin');
        self::assertSame([], PluginRecorder::trail());

        $this->activate(CrmPlugin::SLUG);
        $this->client->jsonRequest('POST', self::HOOKS.'/inbound', ['event' => 'contact.updated'], server: ['HTTP_AUTHORIZATION' => 'Bearer x']);
        self::assertResponseIsSuccessful();
        self::assertSame(['received' => ['event' => 'contact.updated'], 'user' => null], $this->json());
        self::assertArrayNotHasKey('authorization', PluginRecorder::$calls[0]['request']['headers']);

        $this->client->jsonRequest('POST', self::HOOKS.'/elsewhere', []);
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('POST', self::HOOKS.'/boom', []);
        self::assertResponseStatusCodeSame(500);
        self::assertStringNotContainsString('secret failure detail', (string) $this->client->getResponse()->getContent());
    }
}
