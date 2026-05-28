<?php

declare(strict_types=1);

namespace Escalated\Symfony\Event;

use Escalated\Symfony\Entity\Ticket;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when an agent triggers a host-configured custom ticket action.
 * Host applications subscribe to this to run their own work (CRM sync, etc.).
 */
final class TicketCustomActionTriggeredEvent extends Event
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $action,
        public readonly int $userId,
        public readonly array $payload = [],
        public readonly array $metadata = [],
    ) {
    }
}
