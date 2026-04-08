<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Mail\ThreadingHeadersService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

class ThreadingHeadersServiceTest extends TestCase
{
    private ThreadingHeadersService $service;

    protected function setUp(): void
    {
        $this->service = new ThreadingHeadersService('support.example.com');
    }

    public function testGenerateMessageIdForTicket(): void
    {
        $ticket = new Ticket();
        $ticket->setReference('ESC-00001');

        $messageId = $this->service->generateMessageId($ticket);

        $this->assertSame('escalated.ESC-00001@support.example.com', $messageId);
    }

    public function testGenerateMessageIdForReply(): void
    {
        $ticket = new Ticket();
        $ticket->setReference('ESC-00001');

        $messageId = $this->service->generateMessageId($ticket, 42);

        $this->assertSame('escalated.ESC-00001.42@support.example.com', $messageId);
    }

    public function testGetRootMessageId(): void
    {
        $ticket = new Ticket();
        $ticket->setReference('ESC-00001');

        $rootId = $this->service->getRootMessageId($ticket);

        $this->assertSame('escalated.ESC-00001@support.example.com', $rootId);
    }

    public function testApplyHeadersToNewTicketEmail(): void
    {
        $ticket = new Ticket();
        $ticket->setReference('ESC-00001');

        $email = new Email();
        $email->from('support@example.com')->to('customer@example.com')->subject('Test');

        $result = $this->service->applyHeaders($email, $ticket);

        $headers = $result->getHeaders();
        $this->assertNotNull($headers->get('Message-ID'));
        $this->assertStringContainsString('escalated.ESC-00001@support.example.com', $headers->get('Message-ID')->getBodyAsString());
    }

    public function testApplyHeadersToReplyEmail(): void
    {
        $ticket = new Ticket();
        $ticket->setReference('ESC-00001');

        $email = new Email();
        $email->from('support@example.com')->to('customer@example.com')->subject('Test');

        $result = $this->service->applyHeaders($email, $ticket, 42);

        $headers = $result->getHeaders();
        $this->assertNotNull($headers->get('Message-ID'));
        $this->assertNotNull($headers->get('In-Reply-To'));
        $this->assertNotNull($headers->get('References'));

        // In-Reply-To and References should point to root message
        $this->assertStringContainsString('escalated.ESC-00001@support.example.com', $headers->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString('escalated.ESC-00001@support.example.com', $headers->get('References')->getBodyAsString());
    }

    public function testApplyHeadersNoInReplyToForNewTicket(): void
    {
        $ticket = new Ticket();
        $ticket->setReference('ESC-00002');

        $email = new Email();
        $email->from('support@example.com')->to('customer@example.com')->subject('Test');

        $result = $this->service->applyHeaders($email, $ticket);

        $headers = $result->getHeaders();
        $this->assertNull($headers->get('In-Reply-To'));
        $this->assertNull($headers->get('References'));
    }

    public function testGetMailDomain(): void
    {
        $this->assertSame('support.example.com', $this->service->getMailDomain());

        $default = new ThreadingHeadersService();
        $this->assertSame('escalated.localhost', $default->getMailDomain());
    }
}
