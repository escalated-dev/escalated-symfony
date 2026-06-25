<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\SideConversation;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\SideConversationService;
use PHPUnit\Framework\TestCase;

final class SideConversationServiceTest extends TestCase
{
    private function service(): SideConversationService
    {
        return new SideConversationService($this->createMock(EntityManagerInterface::class));
    }

    public function testValidChannels(): void
    {
        $service = $this->service();

        self::assertTrue($service->isValidChannel(SideConversation::CHANNEL_INTERNAL));
        self::assertTrue($service->isValidChannel(SideConversation::CHANNEL_EMAIL));
        self::assertFalse($service->isValidChannel('sms'));
        self::assertFalse($service->isValidChannel(''));
    }

    public function testCreateRejectsEmptySubject(): void
    {
        $service = $this->service();

        $this->expectException(\InvalidArgumentException::class);
        $service->create(new Ticket(), '   ', SideConversation::CHANNEL_INTERNAL, 'Body');
    }

    public function testCreateRejectsInvalidChannel(): void
    {
        $service = $this->service();

        $this->expectException(\InvalidArgumentException::class);
        $service->create(new Ticket(), 'Subject', 'sms', 'Body');
    }

    public function testCreateRejectsEmptyBody(): void
    {
        $service = $this->service();

        $this->expectException(\InvalidArgumentException::class);
        $service->create(new Ticket(), 'Subject', SideConversation::CHANNEL_INTERNAL, '   ');
    }

    public function testConversationDefaultsToOpen(): void
    {
        $conversation = new SideConversation();

        self::assertTrue($conversation->isOpen());
        self::assertSame(SideConversation::STATUS_OPEN, $conversation->getStatus());
    }
}
