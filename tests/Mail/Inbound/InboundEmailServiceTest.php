<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail\Inbound;

use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Mail\Inbound\InboundAttachment;
use Escalated\Symfony\Mail\Inbound\InboundEmailService;
use Escalated\Symfony\Mail\Inbound\InboundMessage;
use Escalated\Symfony\Mail\Inbound\InboundRouter;
use Escalated\Symfony\Service\TicketService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InboundEmailServiceTest extends TestCase
{
    private InboundRouter&MockObject $router;
    private TicketService&MockObject $tickets;

    protected function setUp(): void
    {
        $this->router = $this->createMock(InboundRouter::class);
        $this->tickets = $this->createMock(TicketService::class);
    }

    private function ticket(int $id): Ticket
    {
        $t = new Ticket();
        $ref = new \ReflectionProperty(Ticket::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($t, $id);

        return $t;
    }

    private function reply(int $id): Reply
    {
        $r = new Reply();
        $ref = new \ReflectionProperty(Reply::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($r, $id);

        return $r;
    }

    private function message(
        string $subject = 'hello',
        string $bodyText = 'body',
        string $fromEmail = 'customer@example.com',
        array $attachments = [],
    ): InboundMessage {
        return new InboundMessage(
            fromEmail: $fromEmail,
            fromName: 'Customer',
            toEmail: 'support@example.com',
            subject: $subject,
            bodyText: $bodyText,
            attachments: $attachments,
        );
    }

    public function testMatchedTicketAddsReplyAndReturnsRepliedToExisting(): void
    {
        $ticket = $this->ticket(42);
        $reply = $this->reply(202);

        $this->router->method('resolveTicket')->willReturn($ticket);
        $this->tickets->expects($this->once())
            ->method('addInboundEmailReply')
            ->with($ticket, 'body')
            ->willReturn($reply);
        $this->tickets->expects($this->never())->method('create');

        $svc = new InboundEmailService($this->router, $this->tickets);
        $result = $svc->process($this->message());

        $this->assertSame(InboundEmailService::OUTCOME_REPLIED_TO_EXISTING, $result->outcome);
        $this->assertSame(42, $result->ticketId);
        $this->assertSame(202, $result->replyId);
        $this->assertSame([], $result->pendingAttachmentDownloads);
    }

    public function testNoMatchWithRealContentCreatesNewTicket(): void
    {
        $newTicket = $this->ticket(101);

        $this->router->method('resolveTicket')->willReturn(null);
        $this->tickets->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data) {
                return $data['subject'] === 'New issue'
                    && $data['description'] === 'real'
                    && $data['guest_email'] === 'customer@example.com';
            }))
            ->willReturn($newTicket);
        $this->tickets->expects($this->never())->method('addInboundEmailReply');

        $svc = new InboundEmailService($this->router, $this->tickets);
        $result = $svc->process($this->message('New issue', 'real'));

        $this->assertSame(InboundEmailService::OUTCOME_CREATED_NEW, $result->outcome);
        $this->assertSame(101, $result->ticketId);
        $this->assertNull($result->replyId);
    }

    public function testEmptySubjectFallsBackToPlaceholder(): void
    {
        $this->router->method('resolveTicket')->willReturn(null);
        $this->tickets->expects($this->once())
            ->method('create')
            ->with($this->callback(fn (array $data) => $data['subject'] === '(no subject)'))
            ->willReturn($this->ticket(1));

        $svc = new InboundEmailService($this->router, $this->tickets);
        $svc->process($this->message(subject: '', bodyText: 'has content'));
    }

    public function testSkipsSnsSubscriptionConfirmation(): void
    {
        $this->router->method('resolveTicket')->willReturn(null);
        $this->tickets->expects($this->never())->method('create');
        $this->tickets->expects($this->never())->method('addInboundEmailReply');

        $svc = new InboundEmailService($this->router, $this->tickets);
        $result = $svc->process($this->message(
            subject: 'SubscriptionConfirmation',
            fromEmail: 'no-reply@sns.amazonaws.com',
        ));

        $this->assertSame(InboundEmailService::OUTCOME_SKIPPED, $result->outcome);
        $this->assertNull($result->ticketId);
    }

    public function testSkipsEmptyBodyAndSubject(): void
    {
        $this->router->method('resolveTicket')->willReturn(null);
        $this->tickets->expects($this->never())->method('create');

        $svc = new InboundEmailService($this->router, $this->tickets);
        $result = $svc->process($this->message(subject: '', bodyText: ''));

        $this->assertSame(InboundEmailService::OUTCOME_SKIPPED, $result->outcome);
    }

    public function testSurfacesProviderHostedAttachments(): void
    {
        $this->router->method('resolveTicket')->willReturn(null);
        $this->tickets->method('create')->willReturn($this->ticket(101));

        $attachments = [
            new InboundAttachment(
                name: 'large.pdf',
                contentType: 'application/pdf',
                sizeBytes: 10_000_000,
                content: null,
                downloadUrl: 'https://mailgun.example/att/large',
            ),
            new InboundAttachment(
                name: 'inline.txt',
                contentType: 'text/plain',
                sizeBytes: 5,
                content: 'hello',
                downloadUrl: null,
            ),
        ];

        $svc = new InboundEmailService($this->router, $this->tickets);
        $result = $svc->process($this->message(
            subject: 'With attachments',
            bodyText: 'See attached',
            attachments: $attachments,
        ));

        $this->assertCount(1, $result->pendingAttachmentDownloads);
        $pending = $result->pendingAttachmentDownloads[0];
        $this->assertSame('large.pdf', $pending->name);
        $this->assertSame('https://mailgun.example/att/large', $pending->downloadUrl);
    }

    /**
     * @dataProvider noiseEmailProvider
     */
    public function testIsNoiseEmail(InboundMessage $message, bool $expected): void
    {
        $this->assertSame($expected, InboundEmailService::isNoiseEmail($message));
    }

    public static function noiseEmailProvider(): array
    {
        return [
            'sns confirmation' => [
                new InboundMessage(
                    fromEmail: 'no-reply@sns.amazonaws.com',
                    fromName: null,
                    toEmail: 'support@example.com',
                    subject: 'SubscriptionConfirmation',
                    bodyText: '',
                ),
                true,
            ],
            'empty body and subject' => [
                new InboundMessage(
                    fromEmail: 'customer@example.com',
                    fromName: null,
                    toEmail: 'support@example.com',
                    subject: '',
                    bodyText: '',
                ),
                true,
            ],
            'real content' => [
                new InboundMessage(
                    fromEmail: 'customer@example.com',
                    fromName: null,
                    toEmail: 'support@example.com',
                    subject: 'real',
                    bodyText: 'content',
                ),
                false,
            ],
        ];
    }
}
