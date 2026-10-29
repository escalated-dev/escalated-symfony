<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Plugins;

use Escalated\Symfony\Plugin\AbstractPlugin;
use Escalated\Symfony\Plugin\Bridge\PluginBridge;
use Escalated\Symfony\Plugin\ContextHandler;
use Escalated\Symfony\Plugin\PluginRegistry;
use Escalated\Symfony\Plugin\RegisteredPlugin;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Identity: the contract's slug, and two plugins with one slug failing loudly
 * with both names.
 */
final class PluginRegistrationTest extends PluginTestCase
{
    protected function setUp(): void
    {
        $this->boot();
    }

    public function testTheSlugFollowsTheContract(): void
    {
        self::assertSame('escalated-dev--plugin-slack', RegisteredPlugin::slugFor('@escalated-dev/plugin-slack'));
        self::assertSame('acme--billing', RegisteredPlugin::slugFor('acme/billing'));
        self::assertSame('my-plugin-v2', RegisteredPlugin::slugFor('my plugin.v2'));
    }

    public function testTwoPluginsWithOneSlugAreAConfigurationErrorNamingBoth(): void
    {
        $registry = new PluginRegistry(
            [self::plugin('@acme/crm'), self::plugin('acme/crm')],
            new PluginBridge(new NullLogger()),
            static::entityManager(),
            $this->service(ContextHandler::class),
            $this->service(AuthorizationCheckerInterface::class),
            new NullLogger(),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Plugins "@acme/crm" and "acme/crm" share the slug "acme--crm"');
        $registry->all();
    }

    private static function plugin(string $name): AbstractPlugin
    {
        return new class($name) extends AbstractPlugin {
            public function __construct(private readonly string $name)
            {
            }

            public function manifest(): array
            {
                return ['name' => $this->name];
            }
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }
}
