<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketLink;
use Escalated\Symfony\Service\TicketLinkService;
use PHPUnit\Framework\TestCase;

final class TicketLinkServiceTest extends TestCase
{
    private function service(): TicketLinkService
    {
        return new TicketLinkService($this->createMock(EntityManagerInterface::class));
    }

    public function testValidLinkTypes(): void
    {
        $service = $this->service();

        self::assertTrue($service->isValidLinkType(TicketLink::TYPE_PROBLEM_INCIDENT));
        self::assertTrue($service->isValidLinkType(TicketLink::TYPE_PARENT_CHILD));
        self::assertTrue($service->isValidLinkType(TicketLink::TYPE_RELATED));
        self::assertFalse($service->isValidLinkType('bogus'));
        self::assertFalse($service->isValidLinkType(''));
    }

    public function testLinkRejectsInvalidType(): void
    {
        $service = $this->service();

        $this->expectException(\InvalidArgumentException::class);
        $service->link(new Ticket(), new Ticket(), 'bogus');
    }

    public function testLinkRejectsSelfLink(): void
    {
        $service = $this->service();
        $ticket = new Ticket();

        $this->expectException(\InvalidArgumentException::class);
        $service->link($ticket, $ticket, TicketLink::TYPE_RELATED);
    }
}
