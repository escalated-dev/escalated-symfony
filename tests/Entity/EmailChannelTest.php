<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\EmailChannel;
use PHPUnit\Framework\TestCase;

class EmailChannelTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $channel = new EmailChannel();
        $channel->setEmailAddress('support@example.com');
        $channel->setDisplayName('Support');
        $channel->setIsDefault(true);
        $channel->setIsVerified(false);
        $channel->setDkimStatus('pending');
        $channel->setDkimSelector('escalated');
        $channel->setReplyToAddress('noreply@example.com');
        $channel->setSmtpHost('smtp.example.com');
        $channel->setSmtpPort(587);
        $channel->setSmtpProtocol('tls');
        $channel->setSmtpUsername('user');
        $channel->setSmtpPassword('pass');
        $channel->setIsActive(true);

        $this->assertSame('support@example.com', $channel->getEmailAddress());
        $this->assertSame('Support', $channel->getDisplayName());
        $this->assertTrue($channel->isDefault());
        $this->assertFalse($channel->isVerified());
        $this->assertSame('pending', $channel->getDkimStatus());
        $this->assertSame('escalated', $channel->getDkimSelector());
        $this->assertSame('noreply@example.com', $channel->getReplyToAddress());
        $this->assertSame('smtp.example.com', $channel->getSmtpHost());
        $this->assertSame(587, $channel->getSmtpPort());
        $this->assertSame('tls', $channel->getSmtpProtocol());
        $this->assertSame('user', $channel->getSmtpUsername());
        $this->assertSame('pass', $channel->getSmtpPassword());
        $this->assertTrue($channel->isActive());
    }

    public function testFormattedSenderWithDisplayName(): void
    {
        $channel = new EmailChannel();
        $channel->setEmailAddress('support@example.com');
        $channel->setDisplayName('Support Team');

        $this->assertSame('Support Team <support@example.com>', $channel->getFormattedSender());
    }

    public function testFormattedSenderWithoutDisplayName(): void
    {
        $channel = new EmailChannel();
        $channel->setEmailAddress('support@example.com');

        $this->assertSame('support@example.com', $channel->getFormattedSender());
    }

    public function testTimestampsAreSetOnConstruction(): void
    {
        $channel = new EmailChannel();
        $this->assertInstanceOf(\DateTimeImmutable::class, $channel->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $channel->getUpdatedAt());
    }
}
