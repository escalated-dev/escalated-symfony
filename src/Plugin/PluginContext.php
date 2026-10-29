<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin;

/**
 * The SDK's `ctx` for a native plugin, scoped to that plugin. Every method is
 * the same `ctx.*` call an SDK plugin makes over the bridge, served by the
 * same {@see ContextHandler}; {@see call()} reaches any of them by name
 * (`$ctx->call('tickets.create', ['data' => [...]])`).
 */
final class PluginContext
{
    public function __construct(
        private readonly ContextHandler $handler,
        private readonly string $plugin,
    ) {
    }

    public function pluginName(): string
    {
        return $this->plugin;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function call(string $method, array $params = []): mixed
    {
        $method = str_starts_with($method, 'ctx.') ? $method : 'ctx.'.$method;

        return $this->handler->handle($this->plugin, $method, $params);
    }

    /** @return array<string, mixed> */
    public function configAll(): array
    {
        return (array) $this->call('config.all');
    }

    public function configGet(string $key): mixed
    {
        return $this->call('config.get', ['key' => $key]);
    }

    /** @param array<string, mixed> $data */
    public function configSet(array $data): void
    {
        $this->call('config.set', ['data' => $data]);
    }

    public function storeGet(string $collection, string $key): mixed
    {
        return $this->call('store.get', ['collection' => $collection, 'key' => $key]);
    }

    public function storeSet(string $collection, string $key, mixed $value): void
    {
        $this->call('store.set', ['collection' => $collection, 'key' => $key, 'value' => $value]);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $options
     *
     * @return list<array<string, mixed>>
     */
    public function storeQuery(string $collection, array $filter = [], array $options = []): array
    {
        return (array) $this->call('store.query', ['collection' => $collection, 'filter' => $filter, 'options' => $options]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function storeInsert(string $collection, array $data): array
    {
        return (array) $this->call('store.insert', ['collection' => $collection, 'data' => $data]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function storeUpdate(string $collection, string $key, array $data): array
    {
        return (array) $this->call('store.update', ['collection' => $collection, 'key' => $key, 'data' => $data]);
    }

    public function storeDelete(string $collection, string $key): void
    {
        $this->call('store.delete', ['collection' => $collection, 'key' => $key]);
    }

    public function emit(string $hook, mixed $data = []): void
    {
        $this->call('emit', ['hook' => $hook, 'data' => $data]);
    }

    public function log(string $level, string $message, mixed $data = null): void
    {
        $this->call('log', ['level' => $level, 'message' => $message, 'data' => $data]);
    }

    /**
     * @return array{id: int|string, name: ?string, email: ?string}|null
     */
    public function currentUser(): ?array
    {
        $user = $this->call('currentUser');

        return \is_array($user) ? $user : null;
    }
}
