<?php

declare(strict_types=1);

namespace Escalated\Symfony\Plugin\Bridge;

/**
 * The plugin runtime could not be reached, did not answer in time, or
 * answered with an error. `$pluginError` is the last: the runtime is fine,
 * the plugin's handler threw.
 */
final class BridgeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $pluginError = false,
        public readonly bool $timedOut = false,
        public readonly bool $unavailable = false,
    ) {
        parent::__construct($message);
    }
}
