<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Mail\Inbound;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Mail\Inbound\InboundMessage;
use Escalated\Symfony\Mail\Inbound\InboundRouter;
use Escalated\Symfony\Mail\MessageIdUtil;
use Escalated\Symfony\Repository\TicketRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class InboundRouterTest extends TestCase
{
    private const DOMAIN = 'support.example.com';
    private const SECRET = 'test-secret-for-hmac';

    private TicketRepository&MockObject $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(TicketRepository::class);
    }

    private function ticket(int $id, string $reference = 'ESC-00042'): Ticket
    {
        $t = new Ticket();
        $t->setReference($reference);
        $ref = new \ReflectionProperty(Ticket::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($t, $id);

        return $t;
    }

    private function message(
        ?string $inReplyTo = null,
        ?string $references = null,
        string $toEmail = 'support@example.com',
        string $subject = 'hi',
    ): InboundMessage {
        return new InboundMessage(
            fromEmail: 'customer@example.com',
            fromName: 'Customer',
            toEmail: $toEmail,
            subject: $subject,
            bodyText: 'body',
            inReplyTo: $inReplyTo,
            references: $references,
        );
    }

    public function testResolvesTicketFromCanonicalInReplyTo(): void
    {
        $ticket = $this->ticket(42);
        $this->repo->method('find')->with(42)->willReturn($ticket);

        $router = new InboundRouter($this->repo);
        $m = $this->message(inReplyTo: '<ticket-42@support.example.com>');

        $this->assertSame($ticket, $router->resolveTicket($m));
    }

    public function testResolvesTicketFromCanonicalReferencesHeader(): void
    {
        $ticket = $this->ticket(42);
        $this->repo->method('find')->with(42)->willReturn($ticket);

        $router = new InboundRouter($this->repo);
        $m = $this->message(references: '<unrelated@mail.com> <ticket-42@support.example.com>');

        $this->assertSame($ticket, $router->resolveTicket($m));
    }

    public function testVerifiesSignedReplyToWhenSecretConfigured(): void
    {
        $ticket = $this->ticket(42);
        $this->repo->method('find')->with(42)->willReturn($ticket);

        $router = new InboundRouter($this->repo, self::SECRET);
        $to = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $m = $this->message(toEmail: $to);

        $this->assertSame($ticket, $router->resolveTicket($m));
    }

    public function testRejectsForgedReplyToSignature(): void
    {
        $this->repo->expects($this->never())->method('find');

        $router = new InboundRouter($this->repo, 'real-secret');
        $forged = MessageIdUtil::buildReplyTo(42, 'wrong-secret', self::DOMAIN);
        $m = $this->message(toEmail: $forged);

        $this->assertNull($router->resolveTicket($m));
    }

    public function testIgnoresSignedReplyToWhenSecretBlank(): void
    {
        $this->repo->expects($this->never())->method('find');

        $router = new InboundRouter($this->repo);
        $to = MessageIdUtil::buildReplyTo(42, self::SECRET, self::DOMAIN);
        $m = $this->message(toEmail: $to);

        $this->assertNull($router->resolveTicket($m));
    }

    public function testResolvesTicketFromSubjectReferenceTag(): void
    {
        $ticket = $this->ticket(99, 'ESC-00099');
        $this->repo->method('findByReference')->with('ESC-00099')->willReturn($ticket);

        $router = new InboundRouter($this->repo);
        $m = $this->message(subject: 'RE: [ESC-00099] help');

        $this->assertSame($ticket, $router->resolveTicket($m));
    }

    public function testReturnsNullWhenNothingMatches(): void
    {
        $router = new InboundRouter($this->repo);
        $m = $this->message(subject: 'Totally unrelated');

        $this->assertNull($router->resolveTicket($m));
    }

    public function testCandidateHeaderMessageIdsInReplyToFirstThenReferences(): void
    {
        $m = $this->message(
            inReplyTo: '<primary@mail>',
            references: '<a@mail> <b@mail>',
        );

        $this->assertSame(
            ['<primary@mail>', '<a@mail>', '<b@mail>'],
            InboundRouter::candidateHeaderMessageIds($m)
        );
    }

    public function testCandidateHeaderMessageIdsEmptyHeadersYieldsNone(): void
    {
        $this->assertSame([], InboundRouter::candidateHeaderMessageIds($this->message()));
    }

    public function testMessageBodyPrefersTextOverHtml(): void
    {
        $m = new InboundMessage(
            fromEmail: 'a@b', fromName: null, toEmail: 'c@d', subject: 'hi',
            bodyText: 'plain', bodyHtml: '<p>html</p>',
        );
        $this->assertSame('plain', $m->body());

        $htmlOnly = new InboundMessage(
            fromEmail: 'a@b', fromName: null, toEmail: 'c@d', subject: 'hi',
            bodyHtml: '<p>html</p>',
        );
        $this->assertSame('<p>html</p>', $htmlOnly->body());

        $neither = new InboundMessage(
            fromEmail: 'a@b', fromName: null, toEmail: 'c@d', subject: 'hi',
        );
        $this->assertSame('', $neither->body());
    }
}
