<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventSubscriber;

use Escalated\Symfony\Event\TicketCustomActionTriggeredEvent;
use Escalated\Symfony\Service\TicketService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Records an internal note on the ticket whenever a custom action is triggered,
 * giving an audit trail of who ran which action. The note is authored by the
 * triggering agent, so the body need not repeat their name.
 */
final class RecordCustomActionInternalNoteSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TicketService $ticketService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [TicketCustomActionTriggeredEvent::class => 'onCustomActionTriggered'];
    }

    public function onCustomActionTriggered(TicketCustomActionTriggeredEvent $event): void
    {
        $this->ticketService->addReply(
            $event->ticket,
            $event->userId,
            sprintf('Custom action "%s" was triggered.', $event->action),
            true,
        );
    }
}
