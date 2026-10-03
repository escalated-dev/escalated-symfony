<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\InboundEmail;

use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Mail\MessageIdUtil;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Who an inbound email may post as.
 *
 * A thread match is not an identity: ticket references and Message-IDs are
 * guessable. With the inbound secret configured (it is required for the
 * webhook at all) only the signed Reply-To address identifies a ticket, and
 * a matched email becomes a reply only when its sender is the ticket's
 * requester. Anything else is a new ticket.
 */
final class InboundReplySenderTest extends EscalatedWebTestCase
{
    private const WEBHOOK = '/support/escalated/webhook/email/inbound';
    private const SECRET = 'inbound-test-secret';

    public function testTheRequesterReplyingToTheSignedAddressIsAddedAsAGuestReply(): void
    {
        $client = $this->client();
        $ticket = $this->guestTicket('alice@example.com');

        $body = $this->deliver($client, 'Alice@Example.com', $this->signedAddress($ticket));

        self::assertSame('matched', $body['status']);
        self::assertSame($ticket->getId(), $body['ticket_id']);
        $replies = $this->repliesOn($ticket);
        self::assertCount(1, $replies);
        self::assertNull($replies[0]->getAuthorId());
    }

    public function testAStrangerQuotingTheSubjectReferenceOpensANewTicket(): void
    {
        $client = $this->client();
        $ticket = $this->guestTicket('alice@example.com');

        $body = $this->deliver(
            $client,
            'mallory@example.com',
            'support@support.example.com',
            'Re: ['.$ticket->getReference().'] Printer on fire',
        );

        self::assertSame('created', $body['status']);
        self::assertNotSame($ticket->getId(), $body['ticket_id']);
        self::assertCount(0, $this->repliesOn($ticket));
    }

    public function testTheRequesterQuotingOnlyTheSubjectReferenceIsNotThreaded(): void
    {
        $client = $this->client();
        $ticket = $this->guestTicket('alice@example.com');

        $body = $this->deliver(
            $client,
            'alice@example.com',
            'support@support.example.com',
            'Re: ['.$ticket->getReference().'] Printer on fire',
            inReplyTo: MessageIdUtil::buildMessageId((int) $ticket->getId(), null, 'support.example.com'),
        );

        self::assertSame('created', $body['status']);
        self::assertCount(0, $this->repliesOn($ticket));
    }

    public function testAStrangerHoldingTheSignedAddressOfAClosedTicketDoesNotTouchIt(): void
    {
        $client = $this->client();
        $ticket = $this->guestTicket('alice@example.com', Ticket::STATUS_CLOSED);

        $body = $this->deliver($client, 'mallory@example.com', $this->signedAddress($ticket));

        self::assertSame('created', $body['status']);
        self::assertCount(0, $this->repliesOn($ticket));
        self::entityManager()->clear();
        $reloaded = self::entityManager()->getRepository(Ticket::class)->find($ticket->getId());
        self::assertInstanceOf(Ticket::class, $reloaded);
        self::assertSame(Ticket::STATUS_CLOSED, $reloaded->getStatus());
    }

    public function testAForgedSignatureIsNotThreaded(): void
    {
        $client = $this->client();
        $ticket = $this->guestTicket('alice@example.com');

        $forged = MessageIdUtil::buildReplyTo((int) $ticket->getId(), 'not-the-secret', 'support.example.com');
        $body = $this->deliver($client, 'alice@example.com', $forged);

        self::assertSame('created', $body['status']);
        self::assertCount(0, $this->repliesOn($ticket));
    }

    public function testTheRequesterUserReplyingByEmailPostsAsThatUser(): void
    {
        $client = $this->client();
        $customer = $this->user('customer@example.com');
        $ticket = $this->userTicket($customer);

        $body = $this->deliver($client, 'customer@example.com', $this->signedAddress($ticket));

        self::assertSame('matched', $body['status']);
        $replies = $this->repliesOn($ticket);
        self::assertCount(1, $replies);
        self::assertSame((string) $customer->getId(), (string) $replies[0]->getAuthorId());
        self::assertSame(TestUser::class, $replies[0]->getAuthorClass());
    }

    public function testAnAgentAddressInFromIsNeverPostedAsThatAgent(): void
    {
        $client = $this->client();
        $customer = $this->user('customer@example.com');
        $agent = $this->user('agent@example.com');
        $ticket = $this->userTicket($customer);

        $body = $this->deliver($client, 'agent@example.com', $this->signedAddress($ticket));

        self::assertSame('created', $body['status']);
        self::assertCount(0, $this->repliesOn($ticket));
        $agentReplies = self::entityManager()->getRepository(Reply::class)->findBy(['authorId' => $agent->getId()]);
        self::assertCount(0, $agentReplies);
    }

    private function client(): KernelBrowser
    {
        return static::clientWithSchema(['escalated' => ['inbound_secret' => self::SECRET]]);
    }

    private function signedAddress(Ticket $ticket): string
    {
        return MessageIdUtil::buildReplyTo((int) $ticket->getId(), self::SECRET, 'support.example.com');
    }

    private function guestTicket(string $email, string $status = Ticket::STATUS_OPEN): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject('Printer on fire')
            ->setStatus($status)
            ->setReference('ESC-'.random_int(10000, 99999))
            ->setGuestName('Alice')
            ->setGuestEmail($email)
            ->setGuestToken(bin2hex(random_bytes(16)));

        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function userTicket(TestUser $requester): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject('Account question')
            ->setStatus(Ticket::STATUS_OPEN)
            ->setReference('ESC-'.random_int(10000, 99999))
            ->setRequesterId((int) $requester->getId())
            ->setRequesterClass(TestUser::class);

        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function user(string $email): TestUser
    {
        $user = new TestUser($email);
        $em = static::entityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * @return list<Reply>
     */
    private function repliesOn(Ticket $ticket): array
    {
        return array_values(self::entityManager()->getRepository(Reply::class)->findBy(['ticket' => $ticket->getId()]));
    }

    /**
     * @return array<string, mixed>
     */
    private function deliver(
        KernelBrowser $client,
        string $from,
        string $to,
        string $subject = 'Re: Printer on fire',
        ?string $inReplyTo = null,
    ): array {
        $headers = [['Name' => 'Message-ID', 'Value' => '<'.bin2hex(random_bytes(8)).'@mail.client>']];
        if (null !== $inReplyTo) {
            $headers[] = ['Name' => 'In-Reply-To', 'Value' => $inReplyTo];
        }

        $client->request('POST', self::WEBHOOK.'?adapter=postmark', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_ESCALATED_INBOUND_SECRET' => self::SECRET,
        ], (string) json_encode([
            'FromName' => 'Sender',
            'MessageID' => bin2hex(random_bytes(8)),
            'FromFull' => ['Email' => $from, 'Name' => 'Sender'],
            'To' => $to,
            'ToFull' => [['Email' => $to, 'Name' => '']],
            'OriginalRecipient' => $to,
            'Subject' => $subject,
            'TextBody' => 'Any update on this?',
            'HtmlBody' => '',
            'Headers' => $headers,
            'Attachments' => [],
        ]));

        self::assertResponseStatusCodeSame(202);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        return $body;
    }
}
