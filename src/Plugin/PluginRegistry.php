<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Plugin;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Plugin\Bridge\BridgeException;
use Escalated\Symfony\Plugin\Bridge\PluginBridge;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Every plugin this host runs, native and SDK, with its activation state in
 * escalated_plugins, its lifecycle, and the dispatch of actions and filters
 * to the active ones (the plugin contract, ported from escalated-laravel and
 * the escalated-nestjs reference).
 *
 * Native plugins are `escalated.plugin` services and run in this process. SDK
 * plugins are whatever the plugin runtime loaded; the runtime is only started
 * when an SDK plugin is needed. An inactive plugin is never called.
 */
class PluginRegistry
{
    public const LIFECYCLE_ACTIONS = ['plugin.activated', 'plugin.deactivated', 'plugin.uninstalling'];

    /** How deep ctx.emit may nest before an emitted action is dropped. */
    private const MAX_EMIT_DEPTH = 5;

    /** @var array<string, RegisteredPlugin>|null slug => native plugin */
    private ?array $natives = null;

    /** @var array<string, RegisteredPlugin>|null slug => SDK plugin */
    private ?array $sdk = null;

    private int $emitDepth = 0;

    /**
     * @param iterable<PluginInterface> $nativePlugins
     */
    public function __construct(
        private readonly iterable $nativePlugins,
        private readonly PluginBridge $bridge,
        private readonly EntityManagerInterface $em,
        private readonly ContextHandler $contexts,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly LoggerInterface $logger,
        private readonly string $routePrefix = '/support',
    ) {
        $this->contexts->setRegistry($this);
    }

    /**
     * Every registered plugin, slug => plugin. Starts the runtime when the SDK
     * bridge is on.
     *
     * @return array<string, RegisteredPlugin>
     */
    public function all(): array
    {
        return $this->natives() + $this->sdk();
    }

    public function find(string $slug): ?RegisteredPlugin
    {
        return $this->natives()[$slug] ?? ($this->bridge->isEnabled() ? $this->sdk()[$slug] ?? null : null);
    }

    /**
     * The plugin with this slug when it is active, else null.
     */
    public function active(string $slug): ?RegisteredPlugin
    {
        return \in_array($slug, $this->activeSlugs(), true) ? $this->find($slug) : null;
    }

    /**
     * Active plugins, natives first. Starts the runtime only when an active
     * slug is not a native plugin.
     *
     * @return list<RegisteredPlugin>
     */
    public function activePlugins(): array
    {
        $active = [];
        foreach ($this->activeSlugs() as $slug) {
            $plugin = $this->natives()[$slug] ?? null;
            if (null === $plugin && $this->bridge->isEnabled()) {
                $plugin = $this->sdk()[$slug] ?? null;
            }
            if (null !== $plugin) {
                $active[] = $plugin;
            }
        }
        usort($active, static fn (RegisteredPlugin $a, RegisteredPlugin $b): int => [(int) !$a->isNative(), $a->slug] <=> [(int) !$b->isNative(), $b->slug]);

        return $active;
    }

    /**
     * The plugin objects Admin/Plugins/Index lists. No config, ever.
     *
     * @return list<array<string, mixed>>
     */
    public function listing(): array
    {
        $rows = $this->rows();
        $plugins = $this->all();
        ksort($plugins);

        $listing = [];
        foreach ($plugins as $plugin) {
            $row = $rows[$plugin->slug] ?? null;
            $manifest = $plugin->manifest;
            $homepage = self::stringOrNull($manifest['homepage'] ?? $manifest['author_url'] ?? null);
            $listing[] = [
                'slug' => $plugin->slug,
                'name' => self::stringOrNull($manifest['display_name'] ?? null) ?? $plugin->name,
                'description' => self::stringOrNull($manifest['description'] ?? null),
                'version' => $plugin->version(),
                'author' => self::stringOrNull($manifest['author'] ?? null),
                'author_url' => $homepage,
                'homepage' => $homepage,
                'is_active' => (bool) $row?->isActive(),
                'activated_at' => $row?->isActive() ? PluginPayloads::date($row->getActivatedAt()) : null,
                'installed_at' => PluginPayloads::date($row?->getCreatedAt()),
                'source' => $plugin->source,
                'provides' => [
                    'menu_items' => \count(array_filter($plugin->pages(), static fn (array $page): bool => null !== $page['menu'])),
                    'widgets' => \count($plugin->widgets()),
                    'page_components' => \count($plugin->components()),
                ],
            ];
        }

        return $listing;
    }

    // Lifecycle

    /**
     * The row, then onActivate, then `plugin.activated`. False for an unknown
     * slug. Activating an active plugin does nothing.
     */
    public function activate(string $slug): bool
    {
        $plugin = $this->find($slug);
        if (null === $plugin) {
            return false;
        }
        $row = $this->row($slug);
        if (null !== $row && $row->isActive()) {
            return true;
        }

        if (null === $row) {
            $row = new Plugin($slug);
            $this->em->persist($row);
        }
        $row->activate();
        $this->em->flush();

        $this->lifecycle($plugin, 'activate');
        $this->dispatchAction('plugin.activated', ['plugin' => $plugin->name, 'slug' => $slug]);

        return true;
    }

    /**
     * `plugin.deactivated` while the plugin is still active, onDeactivate,
     * then the row. False for an unknown slug.
     */
    public function deactivate(string $slug): bool
    {
        $plugin = $this->find($slug);
        if (null === $plugin) {
            return false;
        }
        $row = $this->row($slug);
        if (null === $row || !$row->isActive()) {
            return true;
        }

        $this->dispatchAction('plugin.deactivated', ['plugin' => $plugin->name, 'slug' => $slug]);
        $this->lifecycle($plugin, 'deactivate');
        $row->deactivate();
        $this->em->flush();

        return true;
    }

    /**
     * `plugin.uninstalling`, deactivation, and the row goes; the plugin stays
     * registered (its code is in the build) and keeps its stored data. A
     * package-managed plugin is refused: the package manager removes it.
     *
     * @return 'uninstalled'|'unknown'|'refused'
     */
    public function uninstall(string $slug): string
    {
        $plugin = $this->find($slug);
        if (null === $plugin) {
            return 'unknown';
        }
        if (RegisteredPlugin::SOURCE_COMPOSER === $plugin->source) {
            return 'refused';
        }

        if ($this->row($slug)?->isActive()) {
            $this->dispatchAction('plugin.uninstalling', ['plugin' => $plugin->name, 'slug' => $slug]);
        }
        $this->deactivate($slug);
        $row = $this->row($slug);
        if (null !== $row) {
            $this->em->remove($row);
            $this->em->flush();
        }

        return 'uninstalled';
    }

    // Hooks

    /**
     * Runs `hook` on every active plugin that handles it. A plugin that
     * throws is logged and never reaches the caller.
     */
    public function dispatchAction(string $hook, mixed $event): void
    {
        $sdk = [];
        $sdkHandles = false;
        foreach ($this->activePlugins() as $plugin) {
            if (!$plugin->isNative()) {
                $sdk[] = $plugin->name;
                $sdkHandles = $sdkHandles || \in_array($hook, $plugin->actionHooks() ?? [], true);
                continue;
            }
            try {
                $plugin->native?->handleAction($hook, \is_array($event) ? $event : ['value' => $event], $this->context($plugin));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Escalated plugins] %s failed on action %s: %s', $plugin->name, $hook, $e->getMessage()), ['exception' => $e]);
            }
        }

        if ($sdkHandles) {
            $this->bridge->action($hook, $event, $sdk, $this->ctxFor($sdk));
        }
    }

    /**
     * Passes `$value` through active plugins' filters for `hook`, lowest
     * priority first. A filter that throws is skipped and the value carries
     * on. SDK filters run in the runtime, in one call, at the place of the
     * lowest-priority one.
     */
    public function applyFilter(string $hook, mixed $value): mixed
    {
        $steps = [];
        $sdk = [];
        $sdkPriority = null;
        foreach ($this->activePlugins() as $plugin) {
            if (!$plugin->isNative()) {
                $sdk[] = $plugin->name;
            }
            foreach ($plugin->filterHooks() as $filter) {
                if ($filter['hook'] !== $hook) {
                    continue;
                }
                if ($plugin->isNative()) {
                    $steps[] = [$filter['priority'], \count($steps), $plugin];
                } else {
                    $sdkPriority = min($sdkPriority ?? $filter['priority'], $filter['priority']);
                }
            }
        }
        if (null !== $sdkPriority) {
            $steps[] = [$sdkPriority, \count($steps), null];
        }
        usort($steps, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($steps as [, , $plugin]) {
            if (null === $plugin) {
                $value = $this->bridge->filter($hook, $value, $sdk, $this->ctxFor($sdk));
                continue;
            }
            try {
                $value = $plugin->native->applyFilter($hook, $value, $this->context($plugin));
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('[Escalated plugins] %s failed on filter %s; skipped: %s', $plugin->name, $hook, $e->getMessage()), ['exception' => $e]);
            }
        }

        return $value;
    }

    /**
     * ctx.emit: an action of the plugin's choosing to every active plugin.
     * The canonical events and the lifecycle actions belong to the host, so
     * an emitted name that is one of them is dropped.
     */
    public function emit(string $plugin, string $hook, mixed $data): void
    {
        if (\in_array($hook, EventNames::CANONICAL, true) || \array_key_exists($hook, EventNames::LEGACY)
            || \in_array($hook, self::LIFECYCLE_ACTIONS, true) || \in_array($hook, ['ticket.reopened', 'ticket.tagged'], true)) {
            $this->logger->warning(sprintf('[Escalated plugins] %s emitted "%s", which belongs to the host; dropped.', $plugin, $hook));

            return;
        }
        if ($this->emitDepth >= self::MAX_EMIT_DEPTH) {
            $this->logger->warning(sprintf('[Escalated plugins] %s emitted "%s" %d levels deep; dropped.', $plugin, $hook, $this->emitDepth));

            return;
        }

        ++$this->emitDepth;
        try {
            $this->dispatchAction($hook, $data);
        } finally {
            --$this->emitDepth;
        }
    }

    /**
     * Calls an endpoint (`$kind` endpoint) or a webhook of an active plugin.
     *
     * @param array<string, mixed> $request {@see PluginHttp::request()}
     *
     * @throws BridgeException for an SDK plugin whose runtime failed
     * @throws \Throwable      whatever a native handler throws
     */
    public function callHttp(RegisteredPlugin $plugin, string $kind, string $method, string $path, array $request): mixed
    {
        if ($plugin->isNative()) {
            $plain = json_decode((string) json_encode($request, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

            return 'webhook' === $kind
                ? $plugin->native?->handleWebhook($method, $path, $plain, $this->context($plugin))
                : $plugin->native?->handleEndpoint($method, $path, $plain, $this->context($plugin));
        }

        $ctx = $this->ctxFor([$plugin->name]);

        return 'webhook' === $kind
            ? $this->bridge->webhook($plugin->name, $method, $path, $request, $ctx)
            : $this->bridge->endpoint($plugin->name, $method, $path, $request, $ctx);
    }

    // UI extensions and access

    /**
     * `escalated.plugins` for the shared screens: active plugins' menu items,
     * page components and dashboard widgets, without the ones whose
     * capability the viewer lacks, and admin menu items for admins only.
     * `{}` when no plugin is active.
     *
     * @return array<string, mixed>
     */
    public function uiExtensions(): array
    {
        $active = $this->activePlugins();
        if ([] === $active) {
            return [];
        }
        $isAdmin = $this->auth->isGranted('ESCALATED_ADMIN');

        $menuItems = [];
        $pageComponents = [];
        $dashboardWidgets = [];
        foreach ($active as $plugin) {
            foreach ($plugin->pages() as $page) {
                $menu = $page['menu'];
                if (null === $menu || !\is_string($menu['label'] ?? null)) {
                    continue;
                }
                $section = \is_string($menu['section'] ?? null) ? $menu['section'] : 'admin';
                if (('admin' === $section && !$isAdmin) || !$this->allows($page['capability'])) {
                    continue;
                }
                $menuItems[] = [
                    'label' => $menu['label'],
                    'route' => $this->pageUrl($plugin, $page['route']),
                    'icon' => \is_string($menu['icon'] ?? null) ? $menu['icon'] : null,
                    'position' => is_numeric($menu['position'] ?? null) ? (int) $menu['position'] : 100,
                    'section' => $section,
                    'plugin' => $plugin->slug,
                ];
            }
            foreach ($plugin->components() as $component) {
                if (!\is_string($component['page'] ?? null) || !\is_string($component['slot'] ?? null) || !\is_string($component['component'] ?? null)
                    || !$this->allows(RegisteredPlugin::capability($component))) {
                    continue;
                }
                $pageComponents[$component['page'].'.'.$component['slot']][] = [
                    'plugin' => $plugin->slug,
                    'component' => $component['component'],
                    'data' => \is_array($component['props'] ?? null) && [] !== $component['props'] ? $component['props'] : new \stdClass(),
                    'position' => is_numeric($component['order'] ?? null) ? (int) $component['order'] : 100,
                ];
            }
            foreach ($plugin->widgets() as $widget) {
                if (!\is_string($widget['component'] ?? null) || !$this->allows(RegisteredPlugin::capability($widget))) {
                    continue;
                }
                $dashboardWidgets[] = [
                    'id' => $plugin->slug.'.'.$widget['component'],
                    'title' => \is_string($widget['label'] ?? null) ? $widget['label'] : $widget['component'],
                    'component' => $widget['component'],
                    'plugin' => $plugin->slug,
                    'position' => is_numeric($widget['order'] ?? null) ? (int) $widget['order'] : 100,
                    'width' => \is_string($widget['size'] ?? null) ? $widget['size'] : 'half',
                ];
            }
        }

        return [
            'menuItems' => $menuItems,
            'pageComponents' => [] === $pageComponents ? new \stdClass() : $pageComponents,
            'dashboardWidgets' => $dashboardWidgets,
        ];
    }

    /**
     * Whether the current user holds `capability`, a security attribute
     * (a role, or anything a voter decides). No capability: yes.
     */
    public function allows(?string $capability): bool
    {
        return null === $capability || $this->auth->isGranted($capability);
    }

    public function pageUrl(RegisteredPlugin $plugin, string $route): string
    {
        return rtrim($this->routePrefix, '/').'/admin/plugins/'.$plugin->slug.'/'.ltrim($route, '/');
    }

    /**
     * The `ctx` a native plugin's handlers get.
     */
    public function context(RegisteredPlugin $plugin): PluginContext
    {
        return new PluginContext($this->contexts, $plugin->name);
    }

    /**
     * The ctx.* handler for a bridge call serving `$plugins`. The runtime
     * names the plugin making each call; a name outside the call's plugins is
     * refused, and with one plugin the call is always that plugin's.
     *
     * @param list<string> $plugins
     *
     * @return callable(string, array<string, mixed>): mixed
     */
    private function ctxFor(array $plugins): callable
    {
        return function (string $method, array $params) use ($plugins): mixed {
            $named = \is_string($params['plugin'] ?? null) ? $params['plugin'] : null;
            if (1 === \count($plugins)) {
                $plugin = $plugins[0];
            } elseif (null !== $named && \in_array($named, $plugins, true)) {
                $plugin = $named;
            } else {
                throw new \RuntimeException(sprintf('%s refused: the call is not serving plugin "%s".', $method, $named ?? ''));
            }
            unset($params['plugin']);

            return $this->contexts->handle($plugin, $method, $params);
        };
    }

    private function lifecycle(RegisteredPlugin $plugin, string $transition): void
    {
        try {
            if ($plugin->isNative()) {
                'activate' === $transition
                    ? $plugin->native?->onActivate($this->context($plugin))
                    : $plugin->native?->onDeactivate($this->context($plugin));

                return;
            }
            'activate' === $transition
                ? $this->bridge->activate($plugin->name, $this->ctxFor([$plugin->name]))
                : $this->bridge->deactivate($plugin->name, $this->ctxFor([$plugin->name]));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[Escalated plugins] %s failed to %s: %s', $plugin->name, $transition, $e->getMessage()), ['exception' => $e]);
        }
    }

    /**
     * @return list<string>
     */
    private function activeSlugs(): array
    {
        return array_values(array_map(
            static fn (Plugin $row): string => $row->getSlug(),
            $this->em->getRepository(Plugin::class)->findBy(['isActive' => true]),
        ));
    }

    /**
     * @return array<string, Plugin>
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->em->getRepository(Plugin::class)->findAll() as $row) {
            $rows[$row->getSlug()] = $row;
        }

        return $rows;
    }

    private function row(string $slug): ?Plugin
    {
        return $this->em->getRepository(Plugin::class)->findOneBy(['slug' => $slug]);
    }

    /**
     * @return array<string, RegisteredPlugin>
     */
    private function natives(): array
    {
        if (null === $this->natives) {
            $natives = [];
            foreach ($this->nativePlugins as $service) {
                $plugin = RegisteredPlugin::native($service);
                self::assertUnique($natives, $plugin);
                $natives[$plugin->slug] = $plugin;
            }
            $this->natives = $natives;
        }

        return $this->natives;
    }

    /**
     * @return array<string, RegisteredPlugin>
     */
    private function sdk(): array
    {
        if (null === $this->sdk) {
            $natives = $this->natives();
            $sdk = [];
            foreach ($this->bridge->manifests() as $manifest) {
                $plugin = RegisteredPlugin::sdk($manifest);
                self::assertUnique($natives + $sdk, $plugin);
                $sdk[$plugin->slug] = $plugin;
            }
            // An unreachable runtime is tried again on the next request.
            if ([] !== $sdk || !$this->bridge->isEnabled()) {
                $this->sdk = $sdk;
            }

            return $sdk;
        }

        return $this->sdk;
    }

    /**
     * Two plugins with one slug is a configuration error.
     *
     * @param array<string, RegisteredPlugin> $known
     */
    private static function assertUnique(array $known, RegisteredPlugin $plugin): void
    {
        $other = $known[$plugin->slug] ?? null;
        if (null !== $other) {
            throw new \LogicException(sprintf('Plugins "%s" and "%s" share the slug "%s"; rename one.', $other->name, $plugin->name, $plugin->slug));
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }
}
