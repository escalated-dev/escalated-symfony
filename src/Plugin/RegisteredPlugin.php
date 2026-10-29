<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

/**
 * One plugin the host knows about: a native plugin service, or an SDK plugin
 * the plugin runtime reported. Both carry an SDK-shaped manifest, which is all
 * the admin screen, routing and UI extensions read.
 */
final class RegisteredPlugin
{
    /** Code-registered in the host's container (a native plugin). */
    public const SOURCE_REGISTERED = 'registered';

    /** Managed by a package manager (an SDK plugin installed with npm). */
    public const SOURCE_COMPOSER = 'composer';

    /**
     * @param array<string, mixed> $manifest
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $source,
        public readonly array $manifest,
        public readonly ?PluginInterface $native = null,
    ) {
    }

    public static function native(PluginInterface $plugin): self
    {
        $manifest = $plugin->manifest();
        $name = self::nameOf($manifest, $plugin::class);

        return new self($name, self::slugFor($name), self::SOURCE_REGISTERED, $manifest, $plugin);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    public static function sdk(array $manifest): self
    {
        $name = self::nameOf($manifest, 'SDK plugin');

        return new self($name, self::slugFor($name), self::SOURCE_COMPOSER, $manifest);
    }

    /**
     * The contract's slug: no leading `@`, `/` as `--`, anything else outside
     * [A-Za-z0-9_-] as `-`. `@escalated-dev/plugin-slack` is
     * `escalated-dev--plugin-slack`.
     */
    public static function slugFor(string $name): string
    {
        $slug = str_replace('/', '--', ltrim($name, '@'));

        return (string) preg_replace('/[^A-Za-z0-9_-]/', '-', $slug);
    }

    public function isNative(): bool
    {
        return null !== $this->native;
    }

    public function version(): string
    {
        return \is_scalar($this->manifest['version'] ?? null) ? (string) $this->manifest['version'] : '1.0.0';
    }

    /**
     * The manifest's pages that can be served: a route (leading slashes
     * dropped) and a component.
     *
     * @return list<array{route: string, component: string, layout: string, capability: ?string, menu: ?array<string, mixed>}>
     */
    public function pages(): array
    {
        $pages = [];
        foreach ($this->listOf('pages') as $page) {
            $route = ltrim(\is_string($page['route'] ?? null) ? $page['route'] : '', '/');
            $component = \is_string($page['component'] ?? null) ? $page['component'] : '';
            if ('' === $route || '' === $component) {
                continue;
            }
            $pages[] = [
                'route' => $route,
                'component' => $component,
                'layout' => \is_string($page['layout'] ?? null) && '' !== $page['layout'] ? $page['layout'] : 'admin',
                'capability' => self::capability($page),
                'menu' => \is_array($page['menu'] ?? null) ? $page['menu'] : null,
            ];
        }

        return $pages;
    }

    /**
     * Pages with an empty route or component, which are skipped.
     */
    public function skippedPageCount(): int
    {
        return \count($this->listOf('pages')) - \count($this->pages());
    }

    /**
     * @return array{route: string, component: string, layout: string, capability: ?string, menu: ?array<string, mixed>}|null
     */
    public function page(string $route): ?array
    {
        $route = ltrim($route, '/');
        foreach ($this->pages() as $page) {
            if ($page['route'] === $route) {
                return $page;
            }
        }

        return null;
    }

    /**
     * @return list<array{method: string, path: string, capability: ?string}>
     */
    public function endpoints(): array
    {
        return $this->routes('endpoints');
    }

    /**
     * @return list<array{method: string, path: string, capability: ?string}>
     */
    public function webhooks(): array
    {
        return $this->routes('webhooks');
    }

    /**
     * The hooks the plugin handles. Null for a native plugin, which is offered
     * every action and decides for itself.
     *
     * @return list<string>|null
     */
    public function actionHooks(): ?array
    {
        if ($this->isNative() && !isset($this->manifest['actionHooks'])) {
            return null;
        }

        return array_values(array_filter($this->listOf('actionHooks', allowScalars: true), 'is_string'));
    }

    /**
     * @return list<array{hook: string, priority: int}>
     */
    public function filterHooks(): array
    {
        $hooks = [];
        foreach ($this->listOf('filterHooks', allowScalars: true) as $filter) {
            if (\is_string($filter)) {
                $hooks[] = ['hook' => $filter, 'priority' => 10];
            } elseif (\is_array($filter) && \is_string($filter['hook'] ?? null)) {
                $hooks[] = ['hook' => $filter['hook'], 'priority' => is_numeric($filter['priority'] ?? null) ? (int) $filter['priority'] : 10];
            }
        }

        return $hooks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function components(): array
    {
        return $this->listOf('components');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function widgets(): array
    {
        return $this->listOf('widgets');
    }

    /**
     * @param array<string, mixed> $item
     */
    public static function capability(array $item): ?string
    {
        return \is_string($item['capability'] ?? null) && '' !== $item['capability'] ? $item['capability'] : null;
    }

    /**
     * @return list<array{method: string, path: string, capability: ?string}>
     */
    private function routes(string $key): array
    {
        $routes = [];
        foreach ($this->listOf($key) as $route) {
            $method = \is_string($route['method'] ?? null) ? strtoupper($route['method']) : '';
            $path = \is_string($route['path'] ?? null) ? $route['path'] : '';
            if ('' === $method || '' === $path) {
                continue;
            }
            $routes[] = ['method' => $method, 'path' => '/'.ltrim($path, '/'), 'capability' => self::capability($route)];
        }

        return $routes;
    }

    /**
     * @return list<mixed>
     */
    private function listOf(string $key, bool $allowScalars = false): array
    {
        $value = $this->manifest[$key] ?? [];
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => \is_array($item) || ($allowScalars && \is_string($item))));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private static function nameOf(array $manifest, string $what): string
    {
        $name = $manifest['name'] ?? null;
        if (!\is_string($name) || '' === trim($name)) {
            throw new \LogicException(sprintf('%s has no manifest name.', $what));
        }

        return $name;
    }
}
