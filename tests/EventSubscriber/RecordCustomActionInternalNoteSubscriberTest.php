<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\EventSubscriber;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\TicketCustomActionTriggeredEvent;
use Escalated\Symfony\EventSubscriber\RecordCustomActionInternalNoteSubscriber;
use Escalated\Symfony\Service\TicketService;
use PHPUnit\Framework\TestCase;

class RecordCustomActionInternalNoteSubscriberTest extends TestCase
{
    public function testSubscribesToCustomActionEvent(): void
    {
        $events = RecordCustomActionInternalNoteSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(TicketCustomActionTriggeredEvent::class, $events);
        $this->assertSame('onCustomActionTriggered', $events[TicketCustomActionTriggeredEvent::class]);
    }

    public function testRecordsInternalNoteAuthoredByTriggeringAgent(): void
    {
        $ticket = new Ticket();
        $ticketService = $this->createMock(TicketService::class);

        $ticketService->expects($this->once())
            ->method('addReply')
            ->with(
                $ticket,
                7,
                'Custom action "sync-crm" was triggered.',
                true,
            );

        $subscriber = new RecordCustomActionInternalNoteSubscriber($ticketService);
        $subscriber->onCustomActionTriggered(
            new TicketCustomActionTriggeredEvent($ticket, 'sync-crm', 7, ['force' => true]),
        );
    }
}
