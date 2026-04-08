<?php

declare(strict_types=1);

namespace Escalated\Symfony\Broadcasting;

/**
 * Configuration for real-time broadcasting.
 *
 * Supports multiple drivers: mercure, custom (webhook), or none.
 */
class BroadcastSettings
{
    public const DRIVER_NONE = 'none';
    public const DRIVER_MERCURE = 'mercure';
    public const DRIVER_CUSTOM = 'custom';

    public function __construct(
        private string $driver = self::DRIVER_NONE,
        private bool $enabled = false,
        /** @var string[] List of event types to broadcast */
        private array $broadcastEvents = [],
        private ?string $mercureHubUrl = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && self::DRIVER_NONE !== $this->driver;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function setDriver(string $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getBroadcastEvents(): array
    {
        return $this->broadcastEvents;
    }

    public function setBroadcastEvents(array $broadcastEvents): self
    {
        $this->broadcastEvents = $broadcastEvents;

        return $this;
    }

    public function shouldBroadcast(string $eventType): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (empty($this->broadcastEvents)) {
            return true;
        }

        return in_array($eventType, $this->broadcastEvents, true);
    }

    public function getMercureHubUrl(): ?string
    {
        return $this->mercureHubUrl;
    }

    public function setMercureHubUrl(?string $mercureHubUrl): self
    {
        $this->mercureHubUrl = $mercureHubUrl;

        return $this;
    }
}
