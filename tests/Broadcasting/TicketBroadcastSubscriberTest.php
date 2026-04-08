<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Broadcasting;

use Escalated\Symfony\Broadcasting\BroadcastSettings;
use Escalated\Symfony\Broadcasting\NullBroadcaster;
use Escalated\Symfony\Broadcasting\TicketBroadcastSubscriber;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;
use PHPUnit\Framework\TestCase;

class TicketBroadcastSubscriberTest extends TestCase
{
    public function testBroadcastsWhenEnabled(): void
    {
        $broadcaster = new NullBroadcaster();
        $settings = new BroadcastSettings(
            driver: BroadcastSettings::DRIVER_CUSTOM,
            enabled: true,
        );
        $subscriber = new TicketBroadcastSubscriber($broadcaster, $settings);

        $ticket = new Ticket();
        $ticket->setReference('ESC-00001');
        $ticket->setSubject('Test ticket');

        $activity = new TicketActivity();
        $activity->setTicket($ticket);
        $activity->setType(TicketActivity::TYPE_STATUS_CHANGED);
        $activity->setCauserId(1);
        $activity->setProperties(['old_status' => 'open', 'new_status' => 'closed']);

        $subscriber->broadcastActivity($ticket, $activity);

        $dispatched = $broadcaster->getDispatched();
        $this->assertCount(1, $dispatched);
        $this->assertSame('status_changed', $dispatched[0]->getType());
        $this->assertSame('ticket.ESC-00001', $dispatched[0]->getChannel());
        $this->assertSame('ESC-00001', $dispatched[0]->getPayload()['ticket_reference']);
    }

    public function testDoesNotBroadcastWhenDisabled(): void
    {
        $broadcaster = new NullBroadcaster();
        $settings = new BroadcastSettings();
        $subscriber = new TicketBroadcastSubscriber($broadcaster, $settings);

        $ticket = new Ticket();
        $ticket->setReference('ESC-00001');

        $activity = new TicketActivity();
        $activity->setTicket($ticket);
        $activity->setType(TicketActivity::TYPE_REPLIED);

        $subscriber->broadcastActivity($ticket, $activity);

        $this->assertEmpty($broadcaster->getDispatched());
    }

    public function testDoesNotBroadcastFilteredEvents(): void
    {
        $broadcaster = new NullBroadcaster();
        $settings = new BroadcastSettings(
            driver: BroadcastSettings::DRIVER_CUSTOM,
            enabled: true,
            broadcastEvents: ['status_changed'],
        );
        $subscriber = new TicketBroadcastSubscriber($broadcaster, $settings);

        $ticket = new Ticket();
        $ticket->setReference('ESC-00001');

        $activity = new TicketActivity();
        $activity->setTicket($ticket);
        $activity->setType(TicketActivity::TYPE_TAG_ADDED);

        $subscriber->broadcastActivity($ticket, $activity);

        $this->assertEmpty($broadcaster->getDispatched());
    }

    public function testChannelHelpers(): void
    {
        $ticket = new Ticket();
        $ticket->setReference('ESC-00042');

        $this->assertSame('ticket.ESC-00042', TicketBroadcastSubscriber::channelForTicket($ticket));
        $this->assertSame('agent.5', TicketBroadcastSubscriber::channelForAgent(5));
    }

    public function testNullBroadcasterTracksAndResets(): void
    {
        $broadcaster = new NullBroadcaster();
        $settings = new BroadcastSettings(
            driver: BroadcastSettings::DRIVER_CUSTOM,
            enabled: true,
        );
        $subscriber = new TicketBroadcastSubscriber($broadcaster, $settings);

        $ticket = new Ticket();
        $ticket->setReference('ESC-00001');

        $activity = new TicketActivity();
        $activity->setTicket($ticket);
        $activity->setType(TicketActivity::TYPE_CREATED);

        $subscriber->broadcastActivity($ticket, $activity);
        $this->assertCount(1, $broadcaster->getDispatched());

        $broadcaster->reset();
        $this->assertEmpty($broadcaster->getDispatched());
    }
}
