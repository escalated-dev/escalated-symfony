<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

/**
 * A native plugin that does nothing until a method is overridden.
 */
abstract class AbstractPlugin implements PluginInterface
{
    public function onActivate(PluginContext $ctx): void
    {
    }

    public function onDeactivate(PluginContext $ctx): void
    {
    }

    public function handleAction(string $hook, array $event, PluginContext $ctx): void
    {
    }

    public function applyFilter(string $hook, mixed $value, PluginContext $ctx): mixed
    {
        return $value;
    }

    public function handleEndpoint(string $method, string $path, array $request, PluginContext $ctx): mixed
    {
        throw new \LogicException(sprintf('%s declares %s %s but does not handle it.', static::class, $method, $path));
    }

    public function handleWebhook(string $method, string $path, array $request, PluginContext $ctx): mixed
    {
        throw new \LogicException(sprintf('%s declares webhook %s %s but does not handle it.', static::class, $method, $path));
    }
}
