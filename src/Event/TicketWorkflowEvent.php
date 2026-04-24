<?php

declare(strict_types=1);

namespace Escalated\Symfony\Event;

use Escalated\Symfony\Entity\Ticket;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Generic domain event emitted by TicketService at lifecycle
 * points where a Workflow may need to fire. The `triggerName`
 * matches the Workflow.triggerEvent column.
 *
 * Currently dispatched:
 *   - ticket.updated
 *   - ticket.status_changed
 *   - ticket.assigned
 *   - ticket.priority_changed
 *   - ticket.replied
 *   - ticket.tagged
 *
 * (ticket.created is emitted by the Doctrine postPersist listener
 * so guest-path submissions that don't go through TicketService
 * still fire workflows — see WorkflowTicketListener.)
 */
final class TicketWorkflowEvent extends Event
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $triggerName,
        public readonly Ticket $ticket,
        public readonly array $context = [],
    ) {
    }
}
