<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Repository\TicketRepository;
use Escalated\Symfony\Service\SnoozeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SnoozeServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private TicketRepository&MockObject $ticketRepository;
    private SnoozeService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->ticketRepository = $this->createMock(TicketRepository::class);

        $this->service = new SnoozeService($this->em, $this->ticketRepository);
    }

    public function testSnoozeTicketSetsFieldsAndStatus(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $until = new \DateTimeImmutable('+1 day');
        $result = $this->service->snooze($ticket, $until, 5);

        $this->assertSame(Ticket::STATUS_SNOOZED, $result->getStatus());
        $this->assertSame($until, $result->getSnoozedUntil());
        $this->assertSame(5, $result->getSnoozedBy());
        $this->assertSame(Ticket::STATUS_OPEN, $result->getStatusBeforeSnooze());
        $this->assertTrue($result->isSnoozed());
    }

    public function testSnoozePreservesInProgressStatus(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_IN_PROGRESS);

        $until = new \DateTimeImmutable('+2 hours');
        $result = $this->service->snooze($ticket, $until, 1);

        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $result->getStatusBeforeSnooze());
    }

    public function testSnoozeRejectsClosedTicket(): void
    {
        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_CLOSED);
        $ticket->setClosedAt(new \DateTimeImmutable());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot snooze a resolved or closed ticket.');

        $this->service->snooze($ticket, new \DateTimeImmutable('+1 day'), 1);
    }

    public function testSnoozeRejectsPastTime(): void
    {
        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Snooze time must be in the future.');

        $this->service->snooze($ticket, new \DateTimeImmutable('-1 hour'), 1);
    }

    public function testUnsnoozeRestoresStatus(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_SNOOZED);
        $ticket->setSnoozedUntil(new \DateTimeImmutable('+1 day'));
        $ticket->setSnoozedBy(5);
        $ticket->setStatusBeforeSnooze(Ticket::STATUS_IN_PROGRESS);

        $result = $this->service->unsnooze($ticket, 5);

        $this->assertSame(Ticket::STATUS_IN_PROGRESS, $result->getStatus());
        $this->assertNull($result->getSnoozedUntil());
        $this->assertNull($result->getSnoozedBy());
        $this->assertNull($result->getStatusBeforeSnooze());
        $this->assertFalse($result->isSnoozed());
    }

    public function testUnsnoozeDefaultsToOpen(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_SNOOZED);
        $ticket->setSnoozedUntil(new \DateTimeImmutable('+1 day'));
        $ticket->setStatusBeforeSnooze(null);

        $result = $this->service->unsnooze($ticket);

        $this->assertSame(Ticket::STATUS_OPEN, $result->getStatus());
    }

    public function testUnsnoozeRejectsNonSnoozedTicket(): void
    {
        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ticket is not snoozed.');

        $this->service->unsnooze($ticket);
    }

    public function testIsSnoozedRequiresBothStatusAndDate(): void
    {
        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_SNOOZED);
        // No snoozedUntil set
        $this->assertFalse($ticket->isSnoozed());

        $ticket->setSnoozedUntil(new \DateTimeImmutable('+1 day'));
        $this->assertTrue($ticket->isSnoozed());

        $ticket->setStatus(Ticket::STATUS_OPEN);
        $this->assertFalse($ticket->isSnoozed());
    }

    public function testSnoozeLogsActivity(): void
    {
        $this->em->expects($this->atLeastOnce())->method('persist');
        $this->em->expects($this->atLeastOnce())->method('flush');

        $ticket = new Ticket();
        $ticket->setSubject('Test');
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $until = new \DateTimeImmutable('+1 day');
        $this->service->snooze($ticket, $until, 5);

        $activities = $ticket->getActivities();
        $found = false;
        foreach ($activities as $activity) {
            if (SnoozeService::ACTIVITY_TYPE_SNOOZED === $activity->getType()) {
                $found = true;
                $this->assertSame(5, $activity->getCauserId());
                break;
            }
        }
        $this->assertTrue($found, 'Expected snoozed activity log');
    }
}
