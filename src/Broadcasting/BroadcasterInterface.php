<?php

declare(strict_types=1);

namespace Escalated\Symfony\Broadcasting;

/**
 * Contract for broadcasting events to real-time channels.
 */
interface BroadcasterInterface
{
    public function broadcast(BroadcastableEvent $event): void;
}
