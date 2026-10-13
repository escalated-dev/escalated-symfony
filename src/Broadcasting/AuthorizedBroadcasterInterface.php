<?php

declare(strict_types=1);

namespace Escalated\Symfony\Broadcasting;

interface AuthorizedBroadcasterInterface extends BroadcasterInterface
{
    /** Check $canReceive($userId) immediately before every delivery, including queued messages. */
    public function broadcastAuthorized(BroadcastableEvent $event, callable $canReceive): void;
}
