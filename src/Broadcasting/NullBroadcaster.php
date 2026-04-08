<?php

declare(strict_types=1);

namespace Escalated\Symfony\Broadcasting;

/**
 * No-op broadcaster used when broadcasting is disabled.
 */
class NullBroadcaster implements BroadcasterInterface
{
    /** @var BroadcastableEvent[] */
    private array $dispatched = [];

    public function broadcast(BroadcastableEvent $event): void
    {
        $this->dispatched[] = $event;
    }

    /** @return BroadcastableEvent[] */
    public function getDispatched(): array
    {
        return $this->dispatched;
    }

    public function reset(): void
    {
        $this->dispatched = [];
    }
}
