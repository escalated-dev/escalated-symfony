<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

/**
 * A native plugin: PHP code registered in the host's container, run in the
 * host process. Any service implementing this is autoconfigured with the
 * `escalated.plugin` tag and listed in Admin > Plugins as `registered`.
 *
 * It is the PHP shape of an SDK `definePlugin()` object. Extend
 * {@see AbstractPlugin} and override what the plugin needs.
 */
interface PluginInterface
{
    /**
     * The SDK manifest: `name` (required), `version`, `description`, `author`,
     * `homepage`, `pages`, `components`, `widgets`, `endpoints` and `webhooks`
     * (`[{method, path, capability?}]`), `filterHooks` (`[{hook, priority}]`)
     * and, optionally, `actionHooks`.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array;

    public function onActivate(PluginContext $ctx): void;

    public function onDeactivate(PluginContext $ctx): void;

    /**
     * An action: a canonical event, a lifecycle action, or something another
     * plugin emitted.
     *
     * @param array<string, mixed> $event
     */
    public function handleAction(string $hook, array $event, PluginContext $ctx): void;

    /**
     * A filter this plugin declared in `filterHooks`.
     */
    public function applyFilter(string $hook, mixed $value, PluginContext $ctx): mixed;

    /**
     * An endpoint declared in the manifest. `$path` is the manifest path (with
     * its `:param` segments), and `$request` carries `params`, `query`, `body`,
     * `headers`, `rawBodyBase64` and `clientIp`.
     *
     * @param array<string, mixed> $request
     */
    public function handleEndpoint(string $method, string $path, array $request, PluginContext $ctx): mixed;

    /**
     * A webhook declared in the manifest, as {@see handleEndpoint()}.
     *
     * @param array<string, mixed> $request
     */
    public function handleWebhook(string $method, string $path, array $request, PluginContext $ctx): mixed;
}
