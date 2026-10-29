<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Plugins;

use Escalated\Symfony\Entity\Plugin;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\CrmPlugin;

/**
 * The host starts and serves requests whatever the plugin runtime does: when
 * it cannot start, SDK plugins are simply absent; when it dies, endpoints
 * answer 503 and actions are dropped without failing the request.
 */
final class PluginRuntimeFailureTest extends PluginTestCase
{
    private const SLUG = 'acme--sdk-fixture';

    public function testARuntimeThatCannotStartLeavesTheNativePluginsWorking(): void
    {
        $this->bootWith('--die');
        $this->loginAdmin();

        $plugins = array_column($this->visit('/support/admin/plugins')['props']['plugins'], 'slug');
        self::assertContains(CrmPlugin::SLUG, $plugins);
        self::assertNotContains(self::SLUG, $plugins);

        $this->inertia('POST', '/support/admin/plugins/'.CrmPlugin::SLUG.'/activate');
        self::assertResponseRedirects('/support/admin/plugins', 303);
        $this->client->request('GET', '/support/api/plugins/'.CrmPlugin::SLUG.'/contacts/1');
        self::assertResponseIsSuccessful();
    }

    public function testADeadRuntimeAnswersEndpoints503AndDropsActions(): void
    {
        $this->bootWith('--die-on-endpoint');
        $this->loginAdmin();
        $this->activate(self::SLUG);

        $this->client->request('GET', '/support/api/plugins/'.self::SLUG.'/peek');
        self::assertResponseStatusCodeSame(503);

        // Restarting waits out its backoff; meanwhile the plugin answers 503
        // and a ticket created over HTTP still goes through.
        $this->client->request('GET', '/support/api/plugins/'.self::SLUG.'/peek');
        self::assertResponseStatusCodeSame(503);

        $this->loginCustomer();
        $this->inertia('POST', '/support/customer/tickets', ['subject' => 'Unbothered', 'description' => 'Yes.']);
        self::assertResponseRedirects();
        self::assertTrue(static::entityManager()->getRepository(Plugin::class)->findOneBy(['slug' => self::SLUG])?->isActive());
    }

    private function bootWith(string $flag): void
    {
        $this->boot(['plugins' => ['sdk' => [
            'enabled' => true,
            'runtime_command' => [\PHP_BINARY, \dirname(__DIR__).'/Fixtures/plugin-runtime.php', sys_get_temp_dir().'/escalated-symfony-plugin-runtime-failure.log', $flag],
        ]]]);
    }
}
