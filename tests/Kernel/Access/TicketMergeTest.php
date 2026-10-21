<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Ticket merge, like escalated-laravel's TicketMergeService and
 * TicketMergeController (the shared TicketMergeDialog posts
 * `target_reference` to escalated.admin.tickets.merge and searches
 * escalated.admin.tickets.merge-search). The bundle had no merge at all.
 */
final class TicketMergeTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;

    private TestUser $admin;

    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema();
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($this->admin);
        $em->flush();
        $this->client->loginUser($this->admin);

        $dispatcher = static::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(TicketWorkflowEvent::class, function (TicketWorkflowEvent $event): void {
            $this->events[] = $event->triggerName.':'.$event->ticket->getReference();
        });
    }

    public function testMergingMovesRepliesClosesTheSourceAndNotesBothTickets(): void
    {
        $source = $this->ticket('Printer on fire');
        $target = $this->ticket('Printer still on fire');
        $tickets = $this->tickets();
        $tickets->addReply($source, 7, 'First report');
        $tickets->addReply($source, 7, 'Agents only', true);
        $tickets->addReply($target, 7, 'Second report');
        $this->events = [];

        $this->client->request('POST', '/support/admin/tickets/'.$source->getReference().'/merge', ['target_reference' => $target->getReference()]);

        self::assertResponseRedirects('/support/admin/tickets/'.$source->getReference());
        $em = static::entityManager();
        $em->clear();
        $source = $em->getRepository(Ticket::class)->find($source->getId());
        $target = $em->getRepository(Ticket::class)->find($target->getId());

        self::assertSame(Ticket::STATUS_CLOSED, $source->getStatus());
        self::assertNotNull($source->getClosedAt());
        self::assertSame($target->getId(), $source->getMergedIntoId());
        self::assertNull($target->getMergedIntoId());

        self::assertSame(
            ['First report', 'Agents only', 'Second report', 'Ticket '.$source->getReference().' was merged into this ticket.'],
            $this->bodies($target),
        );
        self::assertSame(['This ticket was merged into '.$target->getReference().'.'], $this->bodies($source));

        $note = $em->getRepository(Reply::class)->findOneBy(['ticket' => $target->getId(), 'body' => 'Ticket '.$source->getReference().' was merged into this ticket.']);
        self::assertTrue($note->isInternalNote());
        self::assertNull($note->getAuthorId());
        self::assertSame($source->getReference(), $note->getMetadata()['merge_source'] ?? null);
        self::assertSame($this->admin->getId(), $note->getMetadata()['merged_by'] ?? null);

        self::assertContains('ticket.status_changed:'.$source->getReference(), $this->events, 'webhooks and workflows hear the source close');
    }

    public function testATicketCannotBeMergedIntoItselfOrIntoAMergedTicket(): void
    {
        $a = $this->ticket('A');
        $b = $this->ticket('B');
        $c = $this->ticket('C');

        $this->client->request('POST', '/support/admin/tickets/'.$a->getReference().'/merge', ['target_reference' => $a->getReference()]);
        self::assertResponseRedirects();
        self::assertNull($this->reload($a)->getMergedIntoId());

        $this->client->request('POST', '/support/admin/tickets/'.$b->getReference().'/merge', ['target_reference' => $c->getReference()]);
        self::assertSame($c->getId(), $this->reload($b)->getMergedIntoId());

        $this->client->request('POST', '/support/admin/tickets/'.$a->getReference().'/merge', ['target_reference' => $b->getReference()]);
        self::assertResponseStatusCodeSame(404, 'a merged ticket is not a merge target');
        self::assertNull($this->reload($a)->getMergedIntoId());

        $this->client->request('POST', '/support/admin/tickets/'.$b->getReference().'/merge', ['target_reference' => $a->getReference()]);
        self::assertResponseRedirects();
        self::assertSame($c->getId(), $this->reload($b)->getMergedIntoId(), 'a merged ticket is not merged again');
    }

    public function testAMissingTargetIsRejected(): void
    {
        $a = $this->ticket('A');

        $this->client->request('POST', '/support/admin/tickets/'.$a->getReference().'/merge', []);
        self::assertResponseRedirects();
        self::assertNull($this->reload($a)->getMergedIntoId());

        $this->client->request('POST', '/support/admin/tickets/'.$a->getReference().'/merge', ['target_reference' => 'ESC-NOPE']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testSearchFindsUnmergedTicketsByReferenceOrSubject(): void
    {
        $printer = $this->ticket('Printer on fire');
        $merged = $this->ticket('Printer smoking');
        $other = $this->ticket('Password reset');
        $this->client->request('POST', '/support/admin/tickets/'.$merged->getReference().'/merge', ['target_reference' => $other->getReference()]);

        $this->client->request('GET', '/support/admin/tickets/merge-search?q=Printer', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseIsSuccessful();
        $found = json_decode((string) $this->client->getResponse()->getContent(), true)['tickets'];
        self::assertSame([$printer->getReference()], array_column($found, 'reference'));
        self::assertSame(['id', 'reference', 'subject', 'status', 'created_at'], array_keys($found[0]));

        $this->client->request('GET', '/support/admin/tickets/merge-search?q='.$other->getReference(), server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame([$other->getReference()], array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['tickets'], 'reference'));

        // LIKE wildcards in the term are literal.
        $this->client->request('GET', '/support/admin/tickets/merge-search?q=%25', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['tickets']);
    }

    public function testOnlyAdminsMerge(): void
    {
        $a = $this->ticket('A');
        $b = $this->ticket('B');
        $agent = new TestUser('agent@example.com');
        $em = static::entityManager();
        $em->persist($agent);
        $em->flush();
        $this->client->loginUser($agent);

        $this->client->request('POST', '/support/admin/tickets/'.$a->getReference().'/merge', ['target_reference' => $b->getReference()]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/support/admin/tickets/merge-search?q=A');
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->reload($a)->getMergedIntoId());
    }

    /** @return list<string> */
    private function bodies(Ticket $ticket): array
    {
        $replies = static::entityManager()->getRepository(Reply::class)->findBy(['ticket' => $ticket->getId()], ['id' => 'ASC']);

        return array_map(static fn (Reply $r): string => $r->getBody(), $replies);
    }

    private function reload(Ticket $ticket): Ticket
    {
        $em = static::entityManager();
        $em->clear();
        $fresh = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertInstanceOf(Ticket::class, $fresh);

        return $fresh;
    }

    private function ticket(string $subject): Ticket
    {
        return $this->tickets()->create(['subject' => $subject]);
    }

    private function tickets(): TicketService
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);

        return $service;
    }
}
