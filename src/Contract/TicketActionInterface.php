<?php

declare(strict_types=1);

namespace Escalated\Symfony\Contract;

use Escalated\Symfony\Entity\Ticket;

/**
 * Contract for a host-defined custom ticket action.
 *
 * Services implementing this interface are auto-tagged (`escalated.ticket_action`)
 * and collected by the {@see \Escalated\Symfony\Service\TicketActionRegistry}.
 * Each visible action renders as a button on the agent ticket screen; triggering
 * it dispatches {@see \Escalated\Symfony\Event\TicketCustomActionTriggeredEvent}.
 *
 * For static actions with no logic, configure them under
 * `escalated.ticket_actions` instead of writing a class.
 */
interface TicketActionInterface
{
    /** Stable identifier, used in the action URL and the dispatched event. */
    public function getKey(): string;

    /** Button label shown to the agent. */
    public function getLabel(Ticket $ticket, mixed $user): string;

    /** Whether the action appears at all for this ticket/user. */
    public function isVisible(Ticket $ticket, mixed $user): bool;

    /** Whether the button is clickable (vs. shown but disabled). */
    public function isEnabled(Ticket $ticket, mixed $user): bool;

    /** Button style: 'primary' | 'secondary' | 'danger'. */
    public function getVariant(): string;

    /** Optional confirmation prompt shown before the action fires. */
    public function getConfirmation(Ticket $ticket, mixed $user): ?string;

    /** Arbitrary metadata passed through to the UI and the event (e.g. icon). */
    public function getMetadata(Ticket $ticket, mixed $user): array;
}
