<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Repository\TicketRepository;
use Escalated\Symfony\Service\SkillRoutingService;
use PHPUnit\Framework\TestCase;

class SkillRoutingServiceTest extends TestCase
{
    public function testFindMatchingAgentsReturnsEmptyWhenNoRoutingSignals(): void
    {
        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getTags')->willReturn(new ArrayCollection([]));
        $ticket->method('getDepartment')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('createQueryBuilder');

        $service = new SkillRoutingService($em, $this->createMock(TicketRepository::class));

        $this->assertSame([], $service->findMatchingAgents($ticket));
    }
}
