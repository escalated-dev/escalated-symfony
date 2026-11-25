<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Routing;

use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * The widget and guest portal routes are unauthenticated, and used to be
 * registered on every install with the UI on, whether or not the install
 * used them. `widget.enabled` and `guest.routes_enabled` (or
 * ESCALATED_WIDGET_ENABLED / ESCALATED_GUEST_ROUTES) leave them out; by
 * default they stay. Slack inbound and the cloud webhook have no routes in
 * this bundle.
 */
final class OptionalPublicRoutesTest extends EscalatedWebTestCase
{
    protected function tearDown(): void
    {
        foreach (['ESCALATED_WIDGET_ENABLED', 'ESCALATED_GUEST_ROUTES'] as $env) {
            unset($_ENV[$env], $_SERVER[$env]);
            putenv($env);
        }
        parent::tearDown();
    }

    public function testByDefaultTheWidgetAndGuestRoutesExist(): void
    {
        $routes = $this->routes([]);

        self::assertNotSame([], self::named($routes, 'escalated.widget.'));
        self::assertNotSame([], self::named($routes, 'escalated.guest.'));
        self::assertNotNull($routes->get('escalated.widget.script'));
        self::assertNotNull($routes->get('escalated.guest.tickets.create'));
    }

    public function testWidgetSwitchedOffRegistersNoWidgetRoute(): void
    {
        $routes = $this->routes(['widget' => ['enabled' => false]]);

        self::assertSame([], self::named($routes, 'escalated.widget.'));
        self::assertSame([], self::paths($routes, '/support/widget'));
        // Nothing else goes with it.
        self::assertNotSame([], self::named($routes, 'escalated.guest.'));
        self::assertNotNull($routes->get('escalated.customer.tickets.index'));
        self::assertNotNull($routes->get('escalated.api.tickets.index'));
    }

    public function testGuestRoutesSwitchedOffRegistersNoGuestPortalRoute(): void
    {
        $routes = $this->routes(['guest' => ['routes_enabled' => false]]);

        self::assertSame([], self::named($routes, 'escalated.guest.'));
        self::assertSame([], self::paths($routes, '/support/guest'));
        self::assertNotSame([], self::named($routes, 'escalated.widget.'));
        self::assertNotNull($routes->get('escalated.customer.tickets.index'));
    }

    public function testTheEnvVarsSwitchThemOffWhenTheConfigLeavesThemUnset(): void
    {
        foreach (['ESCALATED_WIDGET_ENABLED', 'ESCALATED_GUEST_ROUTES'] as $env) {
            $_ENV[$env] = $_SERVER[$env] = 'false';
            putenv($env.'=false');
        }

        // A route prefix of its own: the compiled router is cached per configuration.
        $routes = $this->routes(['route_prefix' => '/env-switches']);

        self::assertSame([], self::named($routes, 'escalated.widget.'));
        self::assertSame([], self::named($routes, 'escalated.guest.'));
        self::assertNotNull($routes->get('escalated.customer.tickets.index'));
    }

    public function testASwitchedOffWidgetAnswersNothingAtItsAddress(): void
    {
        $client = static::clientWithSchema(['escalated' => ['widget' => ['enabled' => false], 'guest' => ['routes_enabled' => false]]]);

        $client->request('GET', '/support/widget/api/embed.js');
        self::assertResponseStatusCodeSame(404);

        // No CORS preflight answer for routes that do not exist.
        $client->request('OPTIONS', '/support/widget/api/config', server: [
            'HTTP_ORIGIN' => 'https://shop.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        self::assertNotSame(204, $client->getResponse()->getStatusCode());

        $client->request('GET', '/support/guest/create');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, mixed> $escalated
     */
    private function routes(array $escalated): RouteCollection
    {
        self::bootKernel(['escalated' => $escalated]);
        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        return $router->getRouteCollection();
    }

    /**
     * @return list<string>
     */
    private static function named(RouteCollection $routes, string $prefix): array
    {
        return array_values(array_filter(array_keys($routes->all()), static fn (string $n): bool => str_starts_with($n, $prefix)));
    }

    /**
     * @return list<string>
     */
    private static function paths(RouteCollection $routes, string $prefix): array
    {
        return array_values(array_filter(
            array_map(static fn ($r): string => $r->getPath(), $routes->all()),
            static fn (string $p): bool => str_starts_with($p, $prefix),
        ));
    }
}
