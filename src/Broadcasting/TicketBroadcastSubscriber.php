<?php

declare(strict_types=1);

namespace Escalated\Symfony\Broadcasting;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Listens for ticket-related domain events and broadcasts them.
 *
 * This subscriber is opt-in: it only broadcasts events that are
 * enabled in BroadcastSettings.
 */
class TicketBroadcastSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BroadcasterInterface $broadcaster,
        private readonly BroadcastSettings $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [];
    }

    /**
     * Broadcast a ticket activity event.
     *
     * This method can be called from TicketService or via event dispatch.
     */
    public function broadcastActivity(Ticket $ticket, TicketActivity $activity): void
    {
        $eventType = $activity->getType();

        if (!$this->settings->shouldBroadcast($eventType)) {
            return;
        }

        $channel = sprintf('ticket.%s', $ticket->getReference());

        $event = new BroadcastableEvent(
            type: $eventType,
            channel: $channel,
            payload: [
                'ticket_reference' => $ticket->getReference(),
                'ticket_subject' => $ticket->getSubject(),
                'ticket_status' => $ticket->getStatus(),
                'activity_type' => $activity->getType(),
                'causer_id' => $activity->getCauserId(),
                'properties' => $activity->getProperties(),
            ],
        );

        $this->broadcaster->broadcast($event);
    }

    /**
     * Build a channel name for a ticket.
     */
    public static function channelForTicket(Ticket $ticket): string
    {
        return sprintf('ticket.%s', $ticket->getReference());
    }

    /**
     * Build a channel name for an agent.
     */
    public static function channelForAgent(int $agentId): string
    {
        return sprintf('agent.%d', $agentId);
    }
}
