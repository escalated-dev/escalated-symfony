<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Mail\ThreadingHeadersService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

class ThreadingHeadersServiceTest extends TestCase
{
    private const DOMAIN = 'support.example.com';

    private ThreadingHeadersService $service;

    protected function setUp(): void
    {
        $this->service = new ThreadingHeadersService(self::DOMAIN);
    }

    /**
     * Ticket entity has no public setId; reflect to simulate a
     * persisted ticket.
     */
    private function makeTicket(int $id = 42, string $reference = 'ESC-00042'): Ticket
    {
        $ticket = new Ticket();
        $ticket->setReference($reference);
        $ref = new \ReflectionProperty(Ticket::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($ticket, $id);

        return $ticket;
    }

    public function testGenerateMessageIdForTicket(): void
    {
        $ticket = $this->makeTicket();

        $messageId = $this->service->generateMessageId($ticket);

        $this->assertSame('<ticket-42@support.example.com>', $messageId);
    }

    public function testGenerateMessageIdForReply(): void
    {
        $ticket = $this->makeTicket();

        $messageId = $this->service->generateMessageId($ticket, 7);

        $this->assertSame('<ticket-42-reply-7@support.example.com>', $messageId);
    }

    public function testGetRootMessageId(): void
    {
        $ticket = $this->makeTicket();

        $rootId = $this->service->getRootMessageId($ticket);

        $this->assertSame('<ticket-42@support.example.com>', $rootId);
    }

    public function testApplyHeadersToNewTicketEmail(): void
    {
        $ticket = $this->makeTicket();

        $email = new Email();
        $email->from('support@example.com')->to('customer@example.com')->subject('Test');

        $result = $this->service->applyHeaders($email, $ticket);

        $headers = $result->getHeaders();
        $this->assertNotNull($headers->get('Message-ID'));
        $this->assertStringContainsString('ticket-42@support.example.com', $headers->get('Message-ID')->getBodyAsString());
    }

    public function testApplyHeadersToReplyEmail(): void
    {
        $ticket = $this->makeTicket();

        $email = new Email();
        $email->from('support@example.com')->to('customer@example.com')->subject('Test');

        $result = $this->service->applyHeaders($email, $ticket, 7);

        $headers = $result->getHeaders();
        $this->assertNotNull($headers->get('Message-ID'));
        $this->assertNotNull($headers->get('In-Reply-To'));
        $this->assertNotNull($headers->get('References'));
        $this->assertStringContainsString('ticket-42-reply-7@support.example.com', $headers->get('Message-ID')->getBodyAsString());
        $this->assertStringContainsString('ticket-42@support.example.com', $headers->get('In-Reply-To')->getBodyAsString());
        $this->assertStringContainsString('ticket-42@support.example.com', $headers->get('References')->getBodyAsString());
    }

    public function testApplyHeadersNoInReplyToForNewTicket(): void
    {
        $ticket = $this->makeTicket();

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

    public function testBuildSignedReplyToReturnsNullWhenSecretBlank(): void
    {
        $ticket = $this->makeTicket();

        $this->assertNull($this->service->buildSignedReplyTo($ticket));
    }

    public function testBuildSignedReplyToReturnsAddressWhenSecretConfigured(): void
    {
        $service = new ThreadingHeadersService(self::DOMAIN, 'test-secret-for-hmac');
        $ticket = $this->makeTicket();

        $address = $service->buildSignedReplyTo($ticket);

        $this->assertNotNull($address);
        $this->assertMatchesRegularExpression(
            '/^reply\+42\.[a-f0-9]{8}@support\.example\.com$/',
            $address
        );
    }

    public function testApplyHeadersSetsReplyToWhenSecretConfigured(): void
    {
        $service = new ThreadingHeadersService(self::DOMAIN, 'test-secret');
        $ticket = $this->makeTicket();

        $email = new Email();
        $email->from('support@example.com')->to('customer@example.com')->subject('Test');
        $service->applyHeaders($email, $ticket);

        $replyTo = $email->getReplyTo();
        $this->assertCount(1, $replyTo);
        $this->assertMatchesRegularExpression(
            '/^reply\+42\.[a-f0-9]{8}@support\.example\.com$/',
            $replyTo[0]->getAddress()
        );
    }

    public function testApplyHeadersOmitsReplyToWhenSecretBlank(): void
    {
        $ticket = $this->makeTicket();

        $email = new Email();
        $email->from('support@example.com')->to('customer@example.com')->subject('Test');
        $this->service->applyHeaders($email, $ticket);

        $this->assertCount(0, $email->getReplyTo());
    }
}
