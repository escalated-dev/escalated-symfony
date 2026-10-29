<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin\Bridge;

use Psr\Log\LoggerInterface;

/**
 * JSON-RPC 2.0 over the plugin runtime's stdio: one message per line, at most
 * 10 MB. While it waits for the answer to its own request it serves the
 * `ctx.*` requests the runtime sends back, as escalated-laravel's client does.
 */
final class JsonRpcClient
{
    private const MAX_MESSAGE_SIZE = 10 * 1024 * 1024;

    private int $nextId = 1;

    /**
     * @param resource $stdin
     * @param resource $stdout
     */
    public function __construct(
        private readonly mixed $stdin,
        private readonly mixed $stdout,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sends a request and blocks until its answer arrives, serving the
     * runtime's own requests through `$ctxHandler` meanwhile.
     *
     * @param array<string, mixed>                          $params
     * @param callable(string, array<string, mixed>): mixed $ctxHandler
     *
     * @throws BridgeException on a timeout, a lost connection or an error answer
     */
    public function call(string $method, array $params, int $timeoutSeconds, callable $ctxHandler): mixed
    {
        $id = $this->nextId++;
        $this->write(['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params, 'id' => $id]);

        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new BridgeException(sprintf('Timed out after %d s waiting for the plugin runtime to answer %s.', $timeoutSeconds, $method), timedOut: true);
            }

            $line = $this->readLine($remaining);
            if (null === $line) {
                throw new BridgeException(sprintf('The plugin runtime went away while answering %s.', $method));
            }
            if ('' === $line) {
                continue;
            }

            $message = json_decode($line, true);
            if (!\is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
                $this->logger->warning('[Escalated plugins] the runtime sent something that is not JSON-RPC', ['raw' => substr($line, 0, 200)]);
                continue;
            }

            if (isset($message['method'])) {
                $this->serve($message, $ctxHandler);
                continue;
            }

            if (($message['id'] ?? null) !== $id) {
                $this->logger->warning('[Escalated plugins] the runtime answered a request nobody is waiting for', ['expected' => $id, 'got' => $message['id'] ?? null]);
                continue;
            }

            if (isset($message['error'])) {
                $error = \is_array($message['error']) ? $message['error'] : [];
                throw new BridgeException('The plugin runtime answered with an error: '.(\is_string($error['message'] ?? null) ? $error['message'] : 'unknown error'), pluginError: true);
            }

            return $message['result'] ?? null;
        }
    }

    /**
     * @param array<string, mixed>                          $message
     * @param callable(string, array<string, mixed>): mixed $ctxHandler
     */
    private function serve(array $message, callable $ctxHandler): void
    {
        $id = $message['id'] ?? null;
        $method = \is_string($message['method']) ? $message['method'] : '';
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        try {
            $result = $ctxHandler($method, $params);
            if (null !== $id) {
                $this->write(['jsonrpc' => '2.0', 'result' => $result, 'id' => $id]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[Escalated plugins] '.$method.' failed: '.$e->getMessage());
            if (null !== $id) {
                $this->write(['jsonrpc' => '2.0', 'error' => ['code' => -32000, 'message' => $e->getMessage()], 'id' => $id]);
            }
        }
    }

    private function readLine(float $timeoutSeconds): ?string
    {
        // stream_select() cannot wait on process pipes on Windows, so there
        // the read blocks and only the runtime's own timeouts apply.
        if (\PHP_OS_FAMILY !== 'Windows') {
            $read = [$this->stdout];
            $write = null;
            $except = null;
            $seconds = (int) floor($timeoutSeconds);
            $ready = @stream_select($read, $write, $except, $seconds, (int) (($timeoutSeconds - $seconds) * 1_000_000));
            if (false === $ready) {
                return null;
            }
            if (0 === $ready) {
                return '';
            }
        }

        $line = fgets($this->stdout, self::MAX_MESSAGE_SIZE + 2);
        if (false === $line) {
            return null;
        }
        if (\strlen($line) > self::MAX_MESSAGE_SIZE && !str_ends_with($line, "\n")) {
            throw new BridgeException('The plugin runtime sent a message over 10 MB.');
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @param array<string, mixed> $message
     */
    private function write(array $message): void
    {
        $line = json_encode($message, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (\strlen($line) > self::MAX_MESSAGE_SIZE) {
            throw new BridgeException('A message to the plugin runtime would be over 10 MB.');
        }
        if (!\is_resource($this->stdin) || false === @fwrite($this->stdin, $line."\n")) {
            throw new BridgeException('Could not write to the plugin runtime.');
        }
        @fflush($this->stdin);
    }
}
