<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Broadcasting;

use Escalated\Symfony\Broadcasting\BroadcastableEvent;
use PHPUnit\Framework\TestCase;

class BroadcastableEventTest extends TestCase
{
    public function testConstructAndGetters(): void
    {
        $now = new \DateTimeImmutable();
        $event = new BroadcastableEvent(
            type: 'status_changed',
            channel: 'ticket.ESC-00001',
            payload: ['old_status' => 'open', 'new_status' => 'closed'],
            occurredAt: $now,
        );

        $this->assertSame('status_changed', $event->getType());
        $this->assertSame('ticket.ESC-00001', $event->getChannel());
        $this->assertSame(['old_status' => 'open', 'new_status' => 'closed'], $event->getPayload());
        $this->assertSame($now, $event->getOccurredAt());
    }

    public function testDefaultOccurredAt(): void
    {
        $event = new BroadcastableEvent(type: 'test', channel: 'test', payload: []);

        $this->assertInstanceOf(\DateTimeImmutable::class, $event->getOccurredAt());
    }

    public function testToArray(): void
    {
        $now = new \DateTimeImmutable('2026-04-07T12:00:00+00:00');
        $event = new BroadcastableEvent(
            type: 'replied',
            channel: 'ticket.ESC-00001',
            payload: ['reply_id' => 42],
            occurredAt: $now,
        );

        $array = $event->toArray();

        $this->assertSame('replied', $array['type']);
        $this->assertSame('ticket.ESC-00001', $array['channel']);
        $this->assertSame(['reply_id' => 42], $array['payload']);
        $this->assertSame('2026-04-07T12:00:00+00:00', $array['occurred_at']);
    }
}
