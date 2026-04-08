<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Ticket;
use PHPUnit\Framework\TestCase;

class ChatSessionServiceTest extends TestCase
{
    public function testChatSessionDefaultsToWaitingStatus(): void
    {
        $session = new ChatSession();
        $this->assertSame(ChatSession::STATUS_WAITING, $session->getStatus());
        $this->assertTrue($session->isWaiting());
        $this->assertFalse($session->isActive());
    }

    public function testChatSessionIsActiveWhenStatusActive(): void
    {
        $session = new ChatSession();
        $session->setStatus(ChatSession::STATUS_ACTIVE);
        $this->assertTrue($session->isActive());
        $this->assertFalse($session->isWaiting());
    }

    public function testChatSessionTracksTiming(): void
    {
        $session = new ChatSession();
        $this->assertNotNull($session->getCreatedAt());
        $this->assertNull($session->getEndedAt());
        $this->assertNull($session->getAgentJoinedAt());

        $now = new \DateTimeImmutable();
        $session->setAgentJoinedAt($now);
        $session->setEndedAt($now);
        $this->assertSame($now, $session->getAgentJoinedAt());
        $this->assertSame($now, $session->getEndedAt());
    }

    public function testChatSessionVisitorMetadata(): void
    {
        $session = new ChatSession();
        $session->setVisitorIp('192.168.1.1');
        $session->setVisitorUserAgent('Mozilla/5.0');
        $session->setVisitorPageUrl('https://example.com/pricing');

        $this->assertSame('192.168.1.1', $session->getVisitorIp());
        $this->assertSame('Mozilla/5.0', $session->getVisitorUserAgent());
        $this->assertSame('https://example.com/pricing', $session->getVisitorPageUrl());
    }

    public function testTicketLiveChatStatus(): void
    {
        $ticket = new Ticket();
        $this->assertFalse($ticket->isLiveChat());
        $this->assertFalse($ticket->isChatActive());

        $ticket->setChannel(Ticket::CHANNEL_CHAT);
        $ticket->setStatus(Ticket::STATUS_LIVE);
        $this->assertTrue($ticket->isLiveChat());
        $this->assertTrue($ticket->isChatActive());
    }

    public function testTicketChatNotActiveWhenEnded(): void
    {
        $ticket = new Ticket();
        $ticket->setChannel(Ticket::CHANNEL_CHAT);
        $ticket->setStatus(Ticket::STATUS_LIVE);
        $ticket->setChatEndedAt(new \DateTimeImmutable());

        $this->assertTrue($ticket->isLiveChat());
        $this->assertFalse($ticket->isChatActive());
    }

    public function testTicketChatMetadata(): void
    {
        $ticket = new Ticket();
        $this->assertNull($ticket->getChatMetadata());

        $meta = ['started_at' => '2026-04-08T10:00:00+00:00', 'page_url' => '/pricing'];
        $ticket->setChatMetadata($meta);
        $this->assertSame($meta, $ticket->getChatMetadata());
    }

    public function testTicketChannelField(): void
    {
        $ticket = new Ticket();
        $this->assertNull($ticket->getChannel());

        $ticket->setChannel(Ticket::CHANNEL_EMAIL);
        $this->assertSame('email', $ticket->getChannel());

        $ticket->setChannel(Ticket::CHANNEL_CHAT);
        $this->assertSame('chat', $ticket->getChannel());
    }

    public function testLiveStatusTransitions(): void
    {
        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_LIVE);

        $this->assertTrue($ticket->canTransitionTo(Ticket::STATUS_OPEN));
        $this->assertTrue($ticket->canTransitionTo(Ticket::STATUS_IN_PROGRESS));
        $this->assertTrue($ticket->canTransitionTo(Ticket::STATUS_RESOLVED));
        $this->assertTrue($ticket->canTransitionTo(Ticket::STATUS_CLOSED));
        $this->assertFalse($ticket->canTransitionTo(Ticket::STATUS_SNOOZED));
    }
}
