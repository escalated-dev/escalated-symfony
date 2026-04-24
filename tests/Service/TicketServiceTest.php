<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Repository\TicketRepository;
use Escalated\Symfony\Service\TicketService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class TicketServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private TicketRepository&MockObject $ticketRepository;
    private EventDispatcherInterface&MockObject $dispatcher;
    private TicketService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->ticketRepository = $this->createMock(TicketRepository::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->service = new TicketService(
            $this->em,
            $this->ticketRepository,
            $this->dispatcher,
        );
    }

    public function testCreateTicketSetsDefaultPriority(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = $this->service->create([
            'subject' => 'Test ticket',
            'description' => 'A test description',
            'requester_id' => 1,
        ]);

        $this->assertSame('Test ticket', $ticket->getSubject());
        $this->assertSame('A test description', $ticket->getDescription());
        $this->assertSame(Ticket::PRIORITY_MEDIUM, $ticket->getPriority());
        $this->assertSame(Ticket::STATUS_OPEN, $ticket->getStatus());
    }

    public function testCreateTicketWithExplicitPriority(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = $this->service->create([
            'subject' => 'Urgent issue',
            'priority' => Ticket::PRIORITY_URGENT,
            'requester_id' => 1,
        ]);

        $this->assertSame(Ticket::PRIORITY_URGENT, $ticket->getPriority());
    }

    public function testChangeStatusValidTransition(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $result = $this->service->changeStatus($ticket, Ticket::STATUS_IN_PROGRESS);

        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $result->getStatus());
    }

    public function testChangeStatusInvalidTransitionThrows(): void
    {
        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_CLOSED);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot transition from "closed" to "in_progress"');

        $this->service->changeStatus($ticket, Ticket::STATUS_IN_PROGRESS);
    }

    public function testResolveSetTimestamp(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $result = $this->service->resolve($ticket);

        $this->assertSame(Ticket::STATUS_RESOLVED, $result->getStatus());
        $this->assertNotNull($result->getResolvedAt());
    }

    public function testCloseSetTimestamp(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $result = $this->service->close($ticket);

        $this->assertSame(Ticket::STATUS_CLOSED, $result->getStatus());
        $this->assertNotNull($result->getClosedAt());
    }

    public function testReopenClearsTimestamps(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_RESOLVED);
        $ticket->setResolvedAt(new \DateTimeImmutable());

        $result = $this->service->reopen($ticket);

        $this->assertSame(Ticket::STATUS_REOPENED, $result->getStatus());
        $this->assertNull($result->getResolvedAt());
        $this->assertNull($result->getClosedAt());
    }

    public function testFindByReference(): void
    {
        $ticket = new Ticket();
        $ticket->setSubject('Found ticket');

        $this->ticketRepository->expects($this->once())
            ->method('findByReference')
            ->with('ESC-00001')
            ->willReturn($ticket);

        $result = $this->service->find('ESC-00001');

        $this->assertSame($ticket, $result);
    }

    public function testFindById(): void
    {
        $ticket = new Ticket();
        $ticket->setSubject('Found ticket');

        $this->ticketRepository->expects($this->once())
            ->method('find')
            ->with(42)
            ->willReturn($ticket);

        $result = $this->service->find(42);

        $this->assertSame($ticket, $result);
    }

    public function testUpdateTicketFields(): void
    {
        $this->em->expects($this->once())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Original');
        $ticket->setPriority(Ticket::PRIORITY_LOW);

        $result = $this->service->update($ticket, [
            'subject' => 'Updated',
            'priority' => Ticket::PRIORITY_HIGH,
        ]);

        $this->assertSame('Updated', $result->getSubject());
        $this->assertSame(Ticket::PRIORITY_HIGH, $result->getPriority());
    }

    public function testUpdateDispatchesPriorityChangedWhenPriorityActuallyChanges(): void
    {
        $ticket = new Ticket();
        $ticket->setSubject('Original');
        $ticket->setPriority(Ticket::PRIORITY_LOW);

        $dispatched = [];
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatched) {
                $dispatched[] = $event->triggerName;

                return $event;
            });

        $this->service->update($ticket, ['priority' => Ticket::PRIORITY_HIGH]);

        $this->assertContains('ticket.updated', $dispatched);
        $this->assertContains('ticket.priority_changed', $dispatched);
    }

    public function testUpdateDoesNotDispatchPriorityChangedWhenUnchanged(): void
    {
        $ticket = new Ticket();
        $ticket->setSubject('Original');
        $ticket->setPriority(Ticket::PRIORITY_LOW);

        $dispatched = [];
        $this->dispatcher->method('dispatch')
            ->willReturnCallback(function ($event) use (&$dispatched) {
                $dispatched[] = $event->triggerName;

                return $event;
            });

        $this->service->update($ticket, ['priority' => Ticket::PRIORITY_LOW]);

        $this->assertContains('ticket.updated', $dispatched);
        $this->assertNotContains('ticket.priority_changed', $dispatched);
    }

    public function testAddReplyCreatesReply(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');

        $reply = $this->service->addReply($ticket, 1, 'Hello, world!');

        $this->assertSame('Hello, world!', $reply->getBody());
        $this->assertFalse($reply->isInternalNote());
        $this->assertSame(1, $reply->getAuthorId());
    }

    public function testAddInternalNote(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');

        $reply = $this->service->addReply($ticket, 1, 'Internal note', true);

        $this->assertTrue($reply->isInternalNote());
        $this->assertSame('note', $reply->getType());
    }
}
