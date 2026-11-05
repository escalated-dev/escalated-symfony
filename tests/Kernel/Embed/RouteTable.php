<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Embed;

use Symfony\Component\Routing\RouterInterface;

/**
 * The router's whole table as plain data, in registration order: every
 * route's name, path, methods, controller, defaults, requirements, options,
 * host, schemes and condition. Compared against
 * tests/Fixtures/embed/default-routes.json, a snapshot taken from master
 * before the embeddable admin existed (ADR 2026-11-05-embeddable-admin).
 */
final class RouteTable
{
    public const FIXTURE = __DIR__.'/../../Fixtures/embed/default-routes.json';

    /**
     * The configurations the snapshot covers, by key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function configurations(): array
    {
        $base = ['user_class' => \Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser::class];

        return [
            'default' => $base,
            'newsletters' => [...$base, 'enable_newsletters' => true],
            'headless' => [...$base, 'ui_enabled' => false],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function of(RouterInterface $router): array
    {
        $table = [];
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            $defaults = $route->getDefaults();
            $controller = $defaults['_controller'] ?? null;
            unset($defaults['_controller']);
            ksort($defaults);
            $requirements = $route->getRequirements();
            ksort($requirements);
            $options = $route->getOptions();
            ksort($options);

            $table[] = [
                'name' => $name,
                'path' => $route->getPath(),
                'methods' => $route->getMethods(),
                'controller' => \is_string($controller) ? $controller : get_debug_type($controller),
                'defaults' => $defaults,
                'requirements' => $requirements,
                'options' => $options,
                'host' => $route->getHost(),
                'schemes' => $route->getSchemes(),
                'condition' => $route->getCondition(),
            ];
        }

        return $table;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public static function snapshot(): array
    {
        $decoded = json_decode((string) file_get_contents(self::FIXTURE), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }

    /**
     * The snapshot file: one route per line, so a diff shows which routes changed.
     *
     * @param array<string, list<array<string, mixed>>> $tables
     */
    public static function encode(array $tables): string
    {
        $flags = \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR;
        $groups = [];
        foreach ($tables as $key => $routes) {
            $lines = array_map(static fn (array $route): string => '    '.json_encode($route, $flags), $routes);
            $groups[] = '  '.json_encode($key, $flags).": [\n".implode(",\n", $lines)."\n  ]";
        }

        return "{\n".implode(",\n", $groups)."\n}\n";
    }

    /**
     * Round-trips a table through JSON, so it compares equal to the snapshot
     * (empty objects and lists both decode to arrays).
     *
     * @param list<array<string, mixed>> $table
     *
     * @return list<array<string, mixed>>
     */
    public static function normalised(array $table): array
    {
        $decoded = json_decode((string) json_encode($table, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
