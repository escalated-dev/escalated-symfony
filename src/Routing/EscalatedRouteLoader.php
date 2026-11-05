<?php

declare(strict_types=1);

namespace Escalated\Symfony\Routing;

use Escalated\Symfony\Embed\EmbedConfig;
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
 *  - the JSON API and the inbound email webhook, always;
 *  - the customer, agent, admin, guest and widget UI, when `ui_enabled` is true;
 *  - the newsletter admin, public and webhook routes, when `ui_enabled` and
 *    `enable_newsletters` are both true.
 *
 * Every route is mounted under `route_prefix`, except while the admin is
 * embedded in the host's admin panel (`ui.embed`, ADR
 * 2026-11-05-embeddable-admin): then the admin screens are mounted under the
 * host's `ui.embed.prefix` instead, with the same names, controllers and
 * everything else, and marked as embedded. The two-factor enrolment page
 * (personal, every staff member's) and the newsletter admin stay where they are.
 */
final class EscalatedRouteLoader extends Loader
{
    public const TYPE = 'escalated';

    /**
     * Controller directories (trailing slash) and files, relative to src/Controller.
     */
    private const ALWAYS = ['Api/', 'Plugin/', 'InboundEmailController.php'];

    private const UI = ['Customer/', 'Agent/', 'Admin/', 'Widget/', 'Guest/', 'Auth/'];

    private const NEWSLETTER = ['Newsletter/Admin/', 'Newsletter/Public/', 'Newsletter/Webhook/'];

    /** The controllers whose `/admin` routes move while the admin is embedded. */
    private const EMBEDDABLE = 'Admin/';

    /** Admin routes that stay at their standalone URL while the admin is embedded. */
    private const NOT_EMBEDDED = ['escalated.admin.two-factor.'];

    public function __construct(
        private readonly string $routePrefix,
        private readonly bool $uiEnabled,
        private readonly bool $newslettersEnabled,
        ?string $env = null,
        private readonly ?EmbedConfig $embed = null,
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
        $embedded = [];
        $embedding = null !== $this->embed && $this->embed->enabled();

        foreach ($this->controllerResources() as $controllerResource) {
            $imported = $this->import(\dirname(__DIR__).'/Controller/'.$controllerResource, 'attribute');
            if ($imported instanceof RouteCollection) {
                if ($embedding && self::EMBEDDABLE === $controllerResource) {
                    $embedded = self::embeddable($imported);
                }
                $routes->addCollection($imported);
            }
        }

        $routes->addPrefix($this->routePrefix);

        foreach ($embedded as $name => $path) {
            $route = $routes->get($name);
            if (null !== $route) {
                $route->setPath('/'.$this->embed?->prefix().substr($path, \strlen('/admin')));
                $route->setDefault(EmbedConfig::REQUEST_ATTRIBUTE, true);
            }
        }

        // The set of routes depends on bundle configuration, not only on the
        // controller files, so a configuration change must invalidate the
        // router cache the same way a changed controller does.
        $routes->addResource(new ContainerParametersResource([
            'escalated.route_prefix' => $this->routePrefix,
            'escalated.ui_enabled' => $this->uiEnabled,
            'escalated.enable_newsletters' => $this->newslettersEnabled,
            ...($this->embed?->parameters() ?? []),
        ]));

        return $routes;
    }

    /**
     * The admin routes that move under the host prefix while the admin is
     * embedded, with their path before `route_prefix` is added: every route
     * under `/admin` except the ones that stay.
     *
     * @return array<string, string> name => path
     */
    private static function embeddable(RouteCollection $routes): array
    {
        $paths = [];
        foreach ($routes->all() as $name => $route) {
            $path = $route->getPath();
            if ('/admin' !== $path && !str_starts_with($path, '/admin/')) {
                continue;
            }
            foreach (self::NOT_EMBEDDED as $stays) {
                if (str_starts_with($name, $stays)) {
                    continue 2;
                }
            }
            $paths[$name] = $path;
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private function controllerResources(): array
    {
        $resources = self::ALWAYS;

        if ($this->uiEnabled) {
            $resources = [...$resources, ...self::UI];

            if ($this->newslettersEnabled) {
                $resources = [...$resources, ...self::NEWSLETTER];
            }
        }

        return $resources;
    }
}
