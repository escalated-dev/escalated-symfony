<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel;

use Symfony\Component\Routing\RouterInterface;

/**
 * The bundle's default configuration (`ui_enabled: true`) used to make the
 * container build fail: the bundle handed its routing file to the service
 * container loader, which rejected the first route key as an unknown
 * extension ("escalated_customer"). Only hosts that turned the UI off could
 * boot at all.
 */
final class BundleBootTest extends EscalatedKernelTestCase
{
    public function testTheBundleBootsWithItsDefaultConfiguration(): void
    {
        self::bootKernel();

        self::assertTrue(self::getContainer()->getParameter('escalated.ui_enabled'));
    }

    public function testTheDefaultConfigurationServesTheUiAndTheApi(): void
    {
        self::bootKernel();

        $routes = $this->router()->getRouteCollection();

        self::assertNotNull($routes->get('escalated.customer.tickets.index'), 'customer UI routes are missing');
        self::assertNotNull($routes->get('escalated.agent.tickets.index'), 'agent UI routes are missing');
        self::assertNotNull($routes->get('escalated.api.tickets.index'), 'API routes are missing');
        self::assertSame('/support/agent/tickets', $routes->get('escalated.agent.tickets.index')->getPath());
    }

    public function testTheBundleBootsWithTheUiDisabled(): void
    {
        self::bootKernel(['escalated' => ['ui_enabled' => false]]);

        self::assertFalse(self::getContainer()->getParameter('escalated.ui_enabled'));
    }

    public function testDisablingTheUiDropsTheUiRoutesAndKeepsTheApi(): void
    {
        self::bootKernel(['escalated' => ['ui_enabled' => false]]);

        $routes = $this->router()->getRouteCollection();

        self::assertNotNull($routes->get('escalated.api.tickets.index'), 'the API must stay available with the UI off');
        self::assertNull($routes->get('escalated.customer.tickets.index'), 'customer UI routes must not load with the UI off');
        self::assertNull($routes->get('escalated.agent.tickets.index'), 'agent UI routes must not load with the UI off');
        self::assertNull($routes->get('escalated.admin.tickets.index'), 'admin UI routes must not load with the UI off');
    }

    public function testTheRoutePrefixIsApplied(): void
    {
        self::bootKernel(['escalated' => ['route_prefix' => '/helpdesk']]);

        $route = $this->router()->getRouteCollection()->get('escalated.api.tickets.index');

        self::assertNotNull($route);
        self::assertSame('/helpdesk/api/v1/tickets', $route->getPath());
    }

    private function router(): RouterInterface
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        return $router;
    }
}
