<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\EventListener;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\EventListener\WorkflowTriggerSubscriber;
use Escalated\Symfony\Service\WorkflowEngine;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WorkflowTriggerSubscriberTest extends TestCase
{
    public function testSubscribesToWorkflowEventClass(): void
    {
        $events = WorkflowTriggerSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(TicketWorkflowEvent::class, $events);
        $this->assertSame('onTrigger', $events[TicketWorkflowEvent::class]);
    }

    public function testForwardsTriggerNameAndTicketToEngine(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);
        $ticket = new Ticket();

        $engine->expects($this->once())
            ->method('processEvent')
            ->with('ticket.status_changed', $ticket);

        $subscriber = new WorkflowTriggerSubscriber($engine, new NullLogger());
        $subscriber->onTrigger(new TicketWorkflowEvent('ticket.status_changed', $ticket, ['old_status' => 'open']));
    }

    public function testSwallowsEngineErrorsWithWarningLog(): void
    {
        $engine = $this->createMock(WorkflowEngine::class);
        $engine->method('processEvent')
            ->willThrowException(new \RuntimeException('ruleset broken'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('ruleset broken'));

        $subscriber = new WorkflowTriggerSubscriber($engine, $logger);
        $subscriber->onTrigger(new TicketWorkflowEvent('ticket.replied', new Ticket()));
    }
}
