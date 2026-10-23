<?php

declare(strict_types=1);

namespace Escalated\Symfony\Event;

/**
 * The one event vocabulary for outbound webhooks and workflow triggers
 * (escalated-developer-context `decisions/2026-10-21-event-vocabulary.md`).
 *
 * Only canonical names are emitted or offered in a form. Legacy names are
 * still read from stored data (a workflow's `trigger_event`, a webhook's
 * subscribed `events`) and treated as their canonical name.
 */
final class EventNames
{
    public const TICKET_CREATED = 'ticket.created';
    public const TICKET_UPDATED = 'ticket.updated';
    public const STATUS_CHANGED = 'ticket.status_changed';
    public const TICKET_RESOLVED = 'ticket.resolved';
    public const TICKET_CLOSED = 'ticket.closed';
    public const TICKET_REOPENED = 'ticket.reopened';
    public const TICKET_ASSIGNED = 'ticket.assigned';
    public const TICKET_UNASSIGNED = 'ticket.unassigned';
    public const TICKET_ESCALATED = 'ticket.escalated';
    public const PRIORITY_CHANGED = 'ticket.priority_changed';
    public const DEPARTMENT_CHANGED = 'ticket.department_changed';
    public const TAG_ADDED = 'ticket.tag_added';
    public const TAG_REMOVED = 'ticket.tag_removed';
    public const REPLY_CREATED = 'reply.created';
    public const NOTE_CREATED = 'note.created';
    public const SLA_WARNING = 'sla.warning';
    public const SLA_BREACHED = 'sla.breached';
    public const CHAT_STARTED = 'chat.started';
    public const CHAT_ASSIGNED = 'chat.assigned';
    public const CHAT_TRANSFERRED = 'chat.transferred';
    public const CHAT_MESSAGE = 'chat.message';
    public const CHAT_ENDED = 'chat.ended';

    /**
     * Every canonical name in the ADR.
     */
    public const CANONICAL = [
        self::TICKET_CREATED, self::TICKET_UPDATED, self::STATUS_CHANGED,
        self::TICKET_RESOLVED, self::TICKET_CLOSED, self::TICKET_REOPENED,
        self::TICKET_ASSIGNED, self::TICKET_UNASSIGNED, self::TICKET_ESCALATED,
        self::PRIORITY_CHANGED, self::DEPARTMENT_CHANGED,
        self::TAG_ADDED, self::TAG_REMOVED,
        self::REPLY_CREATED, self::NOTE_CREATED,
        self::SLA_WARNING, self::SLA_BREACHED,
        self::CHAT_STARTED, self::CHAT_ASSIGNED, self::CHAT_TRANSFERRED, self::CHAT_MESSAGE, self::CHAT_ENDED,
    ];

    /**
     * The canonical names this bundle actually emits, to webhooks and
     * workflows alike. A form offers exactly these, so it never offers an
     * event that is not sent. Not emitted yet: `sla.warning` (no warning scan).
     */
    public const EMITTED = [
        self::TICKET_CREATED, self::TICKET_UPDATED, self::STATUS_CHANGED,
        self::TICKET_RESOLVED, self::TICKET_CLOSED, self::TICKET_REOPENED,
        self::TICKET_ASSIGNED, self::TICKET_UNASSIGNED, self::TICKET_ESCALATED,
        self::PRIORITY_CHANGED, self::DEPARTMENT_CHANGED,
        self::TAG_ADDED, self::TAG_REMOVED,
        self::REPLY_CREATED, self::NOTE_CREATED,
        self::SLA_BREACHED,
        self::CHAT_STARTED, self::CHAT_ASSIGNED, self::CHAT_TRANSFERRED, self::CHAT_MESSAGE, self::CHAT_ENDED,
    ];

    /**
     * Legacy name => canonical name. Read, never emitted.
     */
    public const LEGACY = [
        'ticket.replied' => self::REPLY_CREATED,
        'internal_note.added' => self::NOTE_CREATED,
        'internal_note.created' => self::NOTE_CREATED,
        'tag.added' => self::TAG_ADDED,
        'ticket.tagged' => self::TAG_ADDED,
        'tag.removed' => self::TAG_REMOVED,
        'ticket.untagged' => self::TAG_REMOVED,
        'chat.session_started' => self::CHAT_STARTED,
        'chat.session_ended' => self::CHAT_ENDED,
    ];

    /**
     * The status a ticket moves to => the event that announces it, on top of
     * `ticket.status_changed`.
     */
    private const STATUS_EVENTS = [
        'resolved' => self::TICKET_RESOLVED,
        'closed' => self::TICKET_CLOSED,
        'reopened' => self::TICKET_REOPENED,
        'escalated' => self::TICKET_ESCALATED,
    ];

    /**
     * The canonical name for a stored or posted name. Unknown names pass through.
     */
    public static function canonical(string $name): string
    {
        return self::LEGACY[$name] ?? $name;
    }

    /**
     * A canonical name and every legacy name that reads as it, for queries
     * over stored data.
     *
     * @return list<string>
     */
    public static function aliases(string $name): array
    {
        $canonical = self::canonical($name);

        return [$canonical, ...array_keys(array_filter(self::LEGACY, static fn (string $value): bool => $value === $canonical))];
    }

    /**
     * Canonical names for a list of stored or posted names, in order and
     * without duplicates.
     *
     * @param iterable<mixed> $names
     *
     * @return list<string>
     */
    public static function canonicalList(iterable $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $name = self::canonical((string) $name);
            if ('' !== $name && !\in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * The canonical names one dispatched {@see TicketWorkflowEvent} is
     * announced as, to webhooks and workflows alike. A status change to
     * resolved is both `ticket.status_changed` and `ticket.resolved`.
     *
     * @return list<string>
     */
    public static function forEvent(TicketWorkflowEvent $event): array
    {
        // TicketService dispatches ticket.reopened next to the
        // ticket.status_changed it derives from; announcing both would
        // deliver it twice.
        if (self::TICKET_REOPENED === $event->triggerName) {
            return [];
        }

        $name = self::canonical($event->triggerName);
        if ('ticket.tagged' === $event->triggerName && 'removed' === ($event->context['action'] ?? null)) {
            $name = self::TAG_REMOVED;
        }

        $names = [$name];
        // A move between custom statuses that share a built-in status (two
        // `solved` sub-statuses, say) is a status change, but the ticket was
        // already resolved: announce no second ticket.resolved.
        $context = $event->context;
        $sameBuiltIn = \array_key_exists('old_status', $context) && ($context['old_status'] ?? null) === ($context['new_status'] ?? null);
        if (self::STATUS_CHANGED === $name && !$sameBuiltIn) {
            $derived = self::STATUS_EVENTS[(string) ($event->context['new_status'] ?? '')] ?? null;
            if (null !== $derived) {
                $names[] = $derived;
            }
        }

        return $names;
    }
}
