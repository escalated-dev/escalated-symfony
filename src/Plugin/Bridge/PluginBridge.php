<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin\Bridge;

use Psr\Log\LoggerInterface;

/**
 * The SDK bridge: spawns `@escalated-dev/plugin-runtime` on first use and
 * speaks JSON-RPC 2.0 to it over stdio (the plugin contract's "SDK bridge",
 * ported from escalated-laravel's PluginBridge).
 *
 * Off unless `escalated.plugins.sdk.enabled` is true. When the runtime cannot
 * be started, or dies, the host carries on: actions are dropped with a
 * warning, filters return their input, and endpoints answer 503. A dead
 * runtime is restarted on the next use, with exponential backoff capped at
 * five minutes.
 */
class PluginBridge
{
    public const PROTOCOL_VERSION = '1.0';

    public const TIMEOUT_HANDSHAKE = 15;
    public const TIMEOUT_MANIFEST = 15;
    public const TIMEOUT_ACTION = 30;
    public const TIMEOUT_FILTER = 5;
    public const TIMEOUT_ENDPOINT = 30;
    public const TIMEOUT_WEBHOOK = 60;

    private const MAX_BACKOFF_SECONDS = 300;

    /** @var resource|null */
    private mixed $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private ?JsonRpcClient $rpc = null;

    private bool $httpContract = false;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $manifests = null;

    private int $failures = 0;

    private float $lastFailureAt = 0.0;

    /**
     * @param string|list<string> $command the runtime command: a shell string, or an argv list run without a shell
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = false,
        private readonly string|array $command = 'node node_modules/@escalated-dev/plugin-runtime/build/bin/escalated-plugins.js',
        private readonly ?string $cwd = null,
        private readonly string $hostVersion = '0.0.0',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * The SDK plugins the runtime loaded, name => manifest. Empty when the
     * bridge is off or the runtime cannot be started.
     *
     * @return array<string, array<string, mixed>>
     */
    public function manifests(): array
    {
        if (!$this->enabled) {
            return [];
        }
        if (null !== $this->manifests) {
            return $this->manifests;
        }
        if (!$this->ensureRunning()) {
            return [];
        }

        return $this->manifests ?? [];
    }

    /**
     * @param callable(string, array<string, mixed>): mixed $ctx
     */
    public function activate(string $plugin, callable $ctx): void
    {
        $this->request('activate', ['plugin' => $plugin], self::TIMEOUT_ACTION, $ctx);
    }

    /**
     * @param callable(string, array<string, mixed>): mixed $ctx
     */
    public function deactivate(string $plugin, callable $ctx): void
    {
        $this->request('deactivate', ['plugin' => $plugin], self::TIMEOUT_ACTION, $ctx);
    }

    /**
     * Fire-and-forget: a failure is logged, never thrown.
     *
     * @param list<string>                                  $plugins the active plugins, for a runtime that gates on them
     * @param callable(string, array<string, mixed>): mixed $ctx
     */
    public function action(string $hook, mixed $event, array $plugins, callable $ctx): void
    {
        try {
            $this->request('action', ['hook' => $hook, 'event' => $event, 'plugins' => $plugins], self::TIMEOUT_ACTION, $ctx);
        } catch (BridgeException $e) {
            $this->logger->warning(sprintf('[Escalated plugins] action %s was not delivered to SDK plugins: %s', $hook, $e->getMessage()));
        }
    }

    /**
     * Runs one cron schedule. Fire-and-forget: a failure is logged, never thrown.
     *
     * @param callable(string, array<string, mixed>): mixed $ctx
     */
    public function cron(string $plugin, string $schedule, callable $ctx): void
    {
        try {
            $this->request('cron', ['plugin' => $plugin, 'schedule' => $schedule], self::TIMEOUT_ACTION, $ctx);
        } catch (BridgeException $e) {
            $this->logger->warning(sprintf('[Escalated plugins] %s cron "%s" failed: %s', $plugin, $schedule, $e->getMessage()));
        }
    }

    /**
     * A widget's badge: a number or a string, or null when the runtime fails
     * or answers anything else.
     *
     * @param callable(string, array<string, mixed>): mixed $ctx
     */
    public function badge(string $plugin, string $component, callable $ctx): int|float|string|null
    {
        try {
            $answer = $this->request('badge', ['plugin' => $plugin, 'component' => $component], self::TIMEOUT_FILTER, $ctx);
        } catch (BridgeException $e) {
            $this->logger->warning(sprintf('[Escalated plugins] %s badge %s failed: %s', $plugin, $component, $e->getMessage()));

            return null;
        }

        return \is_int($answer) || \is_float($answer) || \is_string($answer) ? $answer : null;
    }

    /**
     * The filtered value, or `$value` when the runtime fails.
     *
     * @param list<string>                                  $plugins
     * @param callable(string, array<string, mixed>): mixed $ctx
     */
    public function filter(string $hook, mixed $value, array $plugins, callable $ctx): mixed
    {
        try {
            return $this->request('filter', ['hook' => $hook, 'value' => $value, 'plugins' => $plugins], self::TIMEOUT_FILTER, $ctx);
        } catch (BridgeException $e) {
            $this->logger->warning(sprintf('[Escalated plugins] filter %s skipped for SDK plugins: %s', $hook, $e->getMessage()));

            return $value;
        }
    }

    /**
     * @param array<string, mixed>                          $request the encoded HTTP request ({@see \Escalated\Symfony\Plugin\PluginHttp::request()})
     * @param callable(string, array<string, mixed>): mixed $ctx
     *
     * @throws BridgeException
     */
    public function endpoint(string $plugin, string $method, string $path, array $request, callable $ctx): mixed
    {
        return $this->http('endpoint', $plugin, $method, $path, $request, self::TIMEOUT_ENDPOINT, $ctx);
    }

    /**
     * @param array<string, mixed>                          $request
     * @param callable(string, array<string, mixed>): mixed $ctx
     *
     * @throws BridgeException
     */
    public function webhook(string $plugin, string $method, string $path, array $request, callable $ctx): mixed
    {
        return $this->http('webhook', $plugin, $method, $path, $request, self::TIMEOUT_WEBHOOK, $ctx);
    }

    /**
     * @param array<string, mixed>                          $request
     * @param callable(string, array<string, mixed>): mixed $ctx
     */
    private function http(string $kind, string $plugin, string $method, string $path, array $request, int $timeout, callable $ctx): mixed
    {
        if (!$this->ensureRunning()) {
            throw new BridgeException('The plugin runtime is not available.', unavailable: true);
        }
        if (!$this->httpContract) {
            throw new BridgeException('Upgrade the plugin runtime to one that supports HTTP contract version 1.', unavailable: true);
        }

        return $this->request($kind, ['plugin' => $plugin, 'method' => $method, 'path' => $path] + $request, $timeout, $ctx);
    }

    /**
     * @param array<string, mixed>                          $params
     * @param callable(string, array<string, mixed>): mixed $ctx
     */
    private function request(string $method, array $params, int $timeout, callable $ctx): mixed
    {
        if (!$this->ensureRunning() || null === $this->rpc) {
            throw new BridgeException('The plugin runtime is not available.', unavailable: true);
        }

        try {
            return $this->rpc->call($method, $params, $timeout, $ctx);
        } catch (BridgeException $e) {
            if (!$e->pluginError) {
                // A timeout or a broken pipe: the runtime is in an unknown
                // state, so the next call starts a fresh one.
                $this->fail();
            }
            throw $e;
        } finally {
            $this->drainStderr();
        }
    }

    private function ensureRunning(): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($this->isAlive()) {
            return true;
        }
        $this->teardown();

        if ($this->failures > 0) {
            $backoff = min(5 * (2 ** ($this->failures - 1)), self::MAX_BACKOFF_SECONDS);
            if (microtime(true) - $this->lastFailureAt < $backoff) {
                return false;
            }
        }

        try {
            $this->spawn();
            $refuse = static fn (string $method): never => throw new \RuntimeException($method.' is not available during the handshake.');
            $answer = $this->rpc?->call('handshake', [
                'protocol_version' => self::PROTOCOL_VERSION,
                'host' => 'symfony',
                'host_version' => $this->hostVersion,
            ], self::TIMEOUT_HANDSHAKE, $refuse);
            if (!\is_array($answer) || true !== ($answer['compatible'] ?? null)) {
                throw new BridgeException(sprintf('The plugin runtime does not speak protocol %s.', self::PROTOCOL_VERSION));
            }
            $this->httpContract = \in_array(1, (array) ($answer['http_contract_versions'] ?? []), true);

            $manifests = $this->rpc?->call('manifest', [], self::TIMEOUT_MANIFEST, $refuse);
            $this->manifests = $this->normaliseManifests($manifests);
            $this->failures = 0;

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[Escalated plugins] could not start the plugin runtime: '.$e->getMessage());
            $this->fail();

            return false;
        } finally {
            $this->drainStderr();
        }
    }

    /**
     * The runtime answers a map of name => manifest; older ones a list.
     *
     * @return array<string, array<string, mixed>>
     */
    private function normaliseManifests(mixed $answer): array
    {
        $manifests = [];
        foreach (\is_array($answer) ? $answer : [] as $key => $manifest) {
            if (!\is_array($manifest)) {
                continue;
            }
            $name = \is_string($manifest['name'] ?? null) ? $manifest['name'] : (\is_string($key) ? $key : null);
            if (null !== $name && '' !== $name) {
                $manifests[$name] = ['name' => $name] + $manifest;
            }
        }

        return $manifests;
    }

    private function spawn(): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            // Pipes cannot be read without blocking on Windows; there the
            // runtime's log goes nowhere rather than filling an unread pipe.
            2 => \PHP_OS_FAMILY === 'Windows' ? ['file', 'NUL', 'w'] : ['pipe', 'w'],
        ];

        $process = @proc_open($this->command, $descriptors, $pipes, $this->cwd);
        if (!\is_resource($process)) {
            throw new BridgeException('Could not start the plugin runtime.', unavailable: true);
        }
        if (isset($pipes[2])) {
            stream_set_blocking($pipes[2], false);
        }

        $this->process = $process;
        $this->pipes = $pipes;
        $this->rpc = new JsonRpcClient($pipes[0], $pipes[1], $this->logger);
    }

    private function isAlive(): bool
    {
        if (!\is_resource($this->process)) {
            return false;
        }

        return (bool) (proc_get_status($this->process)['running'] ?? false);
    }

    private function fail(): void
    {
        ++$this->failures;
        $this->lastFailureAt = microtime(true);
        $this->teardown();
    }

    private function drainStderr(): void
    {
        $stderr = $this->pipes[2] ?? null;
        if (!\is_resource($stderr)) {
            return;
        }
        while (false !== ($line = fgets($stderr)) && '' !== $line) {
            $this->logger->debug('[Escalated plugin runtime] '.rtrim($line, "\r\n"));
        }
    }

    private function teardown(): void
    {
        foreach ($this->pipes as $pipe) {
            if (\is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (\is_resource($this->process)) {
            @proc_terminate($this->process);
            @proc_close($this->process);
        }
        $this->process = null;
        $this->pipes = [];
        $this->rpc = null;
        $this->httpContract = false;
    }

    public function __destruct()
    {
        $this->teardown();
    }
}
