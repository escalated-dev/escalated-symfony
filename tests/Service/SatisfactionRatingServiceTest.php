<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\SatisfactionRatingService;
use PHPUnit\Framework\TestCase;

final class SatisfactionRatingServiceTest extends TestCase
{
    private function service(): SatisfactionRatingService
    {
        return new SatisfactionRatingService($this->createMock(EntityManagerInterface::class));
    }

    public function testValidRatingRange(): void
    {
        $service = $this->service();

        self::assertFalse($service->isValidRating(0));
        self::assertTrue($service->isValidRating(1));
        self::assertTrue($service->isValidRating(3));
        self::assertTrue($service->isValidRating(5));
        self::assertFalse($service->isValidRating(6));
    }

    public function testTicketRateableOnlyWhenResolvedOrClosed(): void
    {
        $service = $this->service();

        self::assertTrue($service->ticketRateable((new Ticket())->setStatus(Ticket::STATUS_RESOLVED)));
        self::assertTrue($service->ticketRateable((new Ticket())->setStatus(Ticket::STATUS_CLOSED)));
        self::assertFalse($service->ticketRateable((new Ticket())->setStatus(Ticket::STATUS_OPEN)));
        self::assertFalse($service->ticketRateable((new Ticket())->setStatus(Ticket::STATUS_IN_PROGRESS)));
        self::assertFalse($service->ticketRateable((new Ticket())->setStatus(Ticket::STATUS_ESCALATED)));
    }

    public function testRateRejectsOutOfRangeScore(): void
    {
        $service = $this->service();
        $ticket = (new Ticket())->setStatus(Ticket::STATUS_RESOLVED);

        $this->expectException(\InvalidArgumentException::class);
        $service->rate($ticket, 9);
    }

    public function testRateRejectsOpenTicket(): void
    {
        $service = $this->service();
        $ticket = (new Ticket())->setStatus(Ticket::STATUS_OPEN);

        $this->expectException(\InvalidArgumentException::class);
        $service->rate($ticket, 5);
    }
}
