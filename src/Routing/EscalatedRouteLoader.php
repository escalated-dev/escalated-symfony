<?php

declare(strict_types=1);

namespace Escalated\Symfony\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Config\ContainerParametersResource;
use Symfony\Component\Routing\RouteCollection;

/**
 * Registers the bundle's routes according to its configuration.
 *
 * A bundle cannot add routes from its container extension: routes are loaded
 * by the router, from the host's routing configuration. The host imports
 * `@EscalatedBundle/config/routes.yaml`, which points here, and this loader
 * decides what that import contains:
 *
 *  - the JSON API, always;
 *  - the customer, agent, admin and widget UI, when `ui_enabled` is true;
 *  - the newsletter admin, public and webhook routes, when `ui_enabled` and
 *    `enable_newsletters` are both true.
 *
 * Every route is mounted under `route_prefix`.
 */
final class EscalatedRouteLoader extends Loader
{
    public const TYPE = 'escalated';

    private const API_DIRECTORIES = ['Api'];

    private const UI_DIRECTORIES = ['Customer', 'Agent', 'Admin', 'Widget'];

    private const NEWSLETTER_DIRECTORIES = ['Newsletter/Admin', 'Newsletter/Public', 'Newsletter/Webhook'];

    public function __construct(
        private readonly string $routePrefix,
        private readonly bool $uiEnabled,
        private readonly bool $newslettersEnabled,
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return self::TYPE === $type;
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();

        foreach ($this->controllerDirectories() as $directory) {
            $imported = $this->import(\dirname(__DIR__).'/Controller/'.$directory.'/', 'attribute');
            if ($imported instanceof RouteCollection) {
                $routes->addCollection($imported);
            }
        }

        $routes->addPrefix($this->routePrefix);

        // The set of routes depends on bundle configuration, not only on the
        // controller files, so a configuration change must invalidate the
        // router cache the same way a changed controller does.
        $routes->addResource(new ContainerParametersResource([
            'escalated.route_prefix' => $this->routePrefix,
            'escalated.ui_enabled' => $this->uiEnabled,
            'escalated.enable_newsletters' => $this->newslettersEnabled,
        ]));

        return $routes;
    }

    /**
     * @return list<string>
     */
    private function controllerDirectories(): array
    {
        $directories = self::API_DIRECTORIES;

        if ($this->uiEnabled) {
            $directories = [...$directories, ...self::UI_DIRECTORIES];

            if ($this->newslettersEnabled) {
                $directories = [...$directories, ...self::NEWSLETTER_DIRECTORIES];
            }
        }

        return $directories;
    }
}
