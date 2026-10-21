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
 *   - ticket.reopened            (alongside ticket.status_changed)
 *   - ticket.assigned
 *   - ticket.unassigned
 *   - ticket.priority_changed
 *   - ticket.department_changed  (escalation rules)
 *   - ticket.tag_added / ticket.tag_removed  (earlier releases: ticket.tagged)
 *   - reply.created              (earlier releases: ticket.replied)
 *   - note.created
 *   - sla.breached
 *   - chat.started, chat.assigned, chat.message, chat.ended  (ChatSessionService)
 *
 * Webhooks and workflows both announce an event under the canonical names
 * {@see EventNames::forEvent()} gives it (decisions/2026-10-21-event-vocabulary.md).
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
