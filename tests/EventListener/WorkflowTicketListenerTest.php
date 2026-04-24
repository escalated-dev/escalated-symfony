<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\EventListener;

use Doctrine\ORM\Event\PostPersistEventArgs;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\EventListener\WorkflowTicketListener;
use Escalated\Symfony\Service\WorkflowEngine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WorkflowTicketListenerTest extends TestCase
{
    public function testDispatchesTicketCreated(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);
        $ticket = new Ticket();

        $engine->expects($this->once())
            ->method('processEvent')
            ->with('ticket.created', $ticket);

        $listener = new WorkflowTicketListener($engine, new NullLogger());
        $listener->handleTicketCreated($ticket);
    }

    public function testSwallowsEngineErrors(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);
        $engine->method('processEvent')
            ->willThrowException(new \RuntimeException('boom'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('boom'));

        $listener = new WorkflowTicketListener($engine, $logger);

        // Should not throw.
        $listener->handleTicketCreated(new Ticket());
    }

    public function testLogFormatMentionsTicketId(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);
        $engine->method('processEvent')
            ->willThrowException(new \RuntimeException('rule #42 failed'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('rule #42 failed'));

        $listener = new WorkflowTicketListener($engine, $logger);
        $listener->handleTicketCreated(new Ticket());
    }
}
