<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\CannedResponse;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketFollower;
use Escalated\Symfony\Service\AssignmentService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The core ticket pages, against the props the shared Vue pages read
 * (escalated-laravel's Agent, Admin and Customer TicketController).
 *
 * These controllers used to hand the Ticket entity itself to the renderer.
 * json_encode() turns an entity into `{}`, and an Inertia serializer walking
 * its getters yields camelCase keys and the guest token, so every page read
 * `undefined` for reference, subject, replies and the rest. Each test names
 * the keys its page reads, nested keys included.
 */
final class TicketPagesTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $admin;
    private TestUser $agent;
    private TestUser $customer;
    private Department $billing;
    private Tag $vip;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $em = static::entityManager();

        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $this->agent = new TestUser('agent@example.com');
        $this->customer = new TestUser('customer@example.com');
        foreach ([$this->admin, $this->agent, $this->customer] as $user) {
            $em->persist($user);
        }
        $em->flush();
        foreach ([$this->admin, $this->agent] as $user) {
            $em->persist((new AgentProfile())->setUserId((int) $user->getId()));
        }

        $this->billing = (new Department())->setName('Billing')->setSlug('billing');
        $em->persist($this->billing);
        $this->vip = (new Tag())->setName('vip')->setSlug('vip')->setColor('#f00');
        $em->persist($this->vip);
        $em->flush();
    }

    public function testAgentQueueIsAPaginatorOfRows(): void
    {
        $ticket = $this->customerTicket('Printer on fire');
        static::getContainer()->get(AssignmentService::class)->assign($ticket, (int) $this->agent->getId());
        $this->tickets()->addTags($ticket, [(int) $this->vip->getId()]);
        $this->tickets()->addReply($ticket, (int) $this->agent->getId(), 'On it', false, TestUser::class);
        $this->guestTicket('Other');

        $this->client->loginUser($this->agent);
        $page = $this->visit('/support/agent/tickets?status=open');

        self::assertSame('Escalated/Agent/TicketIndex', $page['component']);
        $props = $page['props'];
        foreach (['current_page', 'data', 'from', 'last_page', 'links', 'per_page', 'to', 'total', 'path', 'next_page_url', 'prev_page_url'] as $key) {
            self::assertArrayHasKey($key, $props['tickets']);
        }
        self::assertSame(2, $props['tickets']['total']);
        self::assertSame(['status' => 'open'], $props['filters']);
        self::assertSame([['id' => $this->billing->getId(), 'name' => 'Billing']], $props['departments']);
        self::assertSame([['id' => $this->vip->getId(), 'name' => 'vip', 'color' => '#f00']], $props['tags']);

        $row = $this->row($props['tickets']['data'], 'Printer on fire');
        foreach (['id', 'reference', 'subject', 'status', 'priority', 'ticket_type', 'channel', 'requester_name', 'requester_email', 'first_response_due_at', 'resolution_due_at', 'sla_first_response_breached', 'sla_resolution_breached', 'snoozed_until', 'created_at', 'updated_at', 'last_reply_at', 'last_reply_author'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame($ticket->getReference(), $row['reference']);
        self::assertSame(['name' => 'customer@example.com', 'email' => 'customer@example.com'], $row['requester']);
        self::assertSame('agent@example.com', $row['assignee']['name']);
        self::assertSame('Billing', $row['department']['name']);
        self::assertSame('vip', $row['tags'][0]['name']);
        self::assertSame('agent@example.com', $row['latest_reply']['author']['name']);
        self::assertArrayHasKey('created_at', $row['latest_reply']);
        self::assertArrayNotHasKey('guest_token', $row);
    }

    public function testAgentTicketShowGetsTheTicketAndItsContext(): void
    {
        $ticket = $this->customerTicket('Refund please');
        $this->tickets()->addReply($ticket, (int) $this->customer->getId(), 'Hello?', false, TestUser::class);
        $note = $this->tickets()->addReply($ticket, (int) $this->agent->getId(), 'Check the ledger', true, TestUser::class);
        $note->setIsPinned(true);
        $em = static::entityManager();
        $em->persist((new CannedResponse())->setTitle('Thanks')->setBody('Thank you')->setIsShared(true));
        $em->persist((new Macro())->setName('Close it')->setActions([['type' => 'change_status', 'value' => 'closed']])->setIsShared(true));
        $em->persist((new TicketFollower())->setTicketId((int) $ticket->getId())->setUserId((int) $this->agent->getId()));
        $em->flush();
        $this->tickets()->changeStatus($ticket, Ticket::STATUS_IN_PROGRESS, (int) $this->agent->getId());

        $this->client->loginUser($this->agent);
        $page = $this->visit('/support/agent/tickets/'.$ticket->getReference());

        self::assertSame('Escalated/Agent/TicketShow', $page['component']);
        $props = $page['props'];
        foreach (['ticket', 'departments', 'tags', 'cannedResponses', 'macros', 'is_following', 'followers_count', 'customActions'] as $key) {
            self::assertArrayHasKey($key, $props);
        }
        self::assertTrue($props['is_following']);
        self::assertSame(1, $props['followers_count']);
        self::assertSame('Thanks', $props['cannedResponses'][0]['title']);
        self::assertSame('Thank you', $props['cannedResponses'][0]['body']);
        self::assertSame('Close it', $props['macros'][0]['name']);

        $t = $props['ticket'];
        foreach (['id', 'reference', 'subject', 'description', 'status', 'priority', 'channel', 'assigned_to', 'created_at', 'replies', 'attachments', 'activities', 'pinned_notes', 'tags', 'department', 'satisfaction_rating', 'subjects', 'sla_policy', 'requester', 'requester_ticket_count', 'related_tickets', 'chat_session_id', 'chat_started_at', 'chat_messages', 'chat_metadata', 'ticket_type', 'first_response_due_at', 'resolution_due_at', 'sla_first_response_breached', 'sla_resolution_breached'] as $key) {
            self::assertArrayHasKey($key, $t, $key);
        }
        self::assertSame('Refund please', $t['subject']);
        self::assertSame('in_progress', $t['status']);
        self::assertSame(['name' => 'customer@example.com', 'email' => 'customer@example.com'], $t['requester']);
        self::assertSame(1, $t['requester_ticket_count']);

        // Latest first, as Laravel loads them; notes stay in the staff thread.
        self::assertSame(['Check the ledger', 'Hello?'], array_column($t['replies'], 'body'));
        $first = $t['replies'][0];
        foreach (['id', 'body', 'is_internal_note', 'is_pinned', 'author', 'attachments', 'created_at'] as $key) {
            self::assertArrayHasKey($key, $first);
        }
        self::assertTrue($first['is_internal_note']);
        self::assertTrue($first['is_pinned']);
        self::assertSame('agent@example.com', $first['author']['name']);
        self::assertSame('customer@example.com', $t['replies'][1]['author']['name']);

        self::assertSame('Check the ledger', $t['pinned_notes'][0]['body']);
        self::assertSame('agent@example.com', $t['pinned_notes'][0]['author']['name']);

        $activity = $t['activities'][0];
        foreach (['id', 'description', 'created_at', 'created_at_human'] as $key) {
            self::assertArrayHasKey($key, $activity);
        }
        self::assertContains('changed status from open to in progress', array_column($t['activities'], 'description'));

        self::assertArrayNotHasKey('guest_token', $t);
        self::assertArrayNotHasKey('metadata', $t);
    }

    public function testAdminPagesAddAgentsSavedViewsAndSnooze(): void
    {
        $ticket = $this->customerTicket('Admin view');
        $ticket->setSnoozedUntil(new \DateTimeImmutable('+1 day'));
        static::entityManager()->flush();

        $this->client->loginUser($this->admin);
        $page = $this->visit('/support/admin/tickets');
        self::assertSame('Escalated/Admin/Tickets/Index', $page['component']);
        foreach (['tickets', 'filters', 'departments', 'tags', 'agents', 'savedViews'] as $key) {
            self::assertArrayHasKey($key, $page['props']);
        }
        self::assertSame(['admin@example.com', 'agent@example.com'], array_column($page['props']['agents'], 'name'));
        self::assertSame(['id', 'name', 'email'], array_keys($page['props']['agents'][0]));
        self::assertSame('Admin view', $page['props']['tickets']['data'][0]['subject']);

        $page = $this->visit('/support/admin/tickets/'.$ticket->getReference());
        self::assertSame('Escalated/Admin/Tickets/Show', $page['component']);
        foreach (['ticket', 'departments', 'tags', 'cannedResponses', 'agents', 'macros', 'is_following', 'followers_count', 'is_snoozed'] as $key) {
            self::assertArrayHasKey($key, $page['props']);
        }
        self::assertTrue($page['props']['is_snoozed']);
        self::assertTrue($page['props']['ticket']['is_snoozed']);
        self::assertNotNull($page['props']['ticket']['snoozed_until']);
    }

    public function testAgentsAreNotAdmins(): void
    {
        $this->client->loginUser($this->agent);
        $this->client->request('GET', '/support/admin/tickets');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDeskWritesTheSharedPagesPost(): void
    {
        $ticket = $this->customerTicket('Writes');
        $ref = $ticket->getReference();
        $em = static::entityManager();
        $macro = (new Macro())->setName('Urgent')->setActions([['type' => 'set_priority', 'value' => 'urgent']])->setIsShared(true);
        $em->persist($macro);
        $em->flush();

        $this->client->loginUser($this->admin);
        $base = '/support/admin/tickets/'.$ref;

        $this->post($base.'/reply', ['body' => 'We are looking', 'is_internal_note' => false, 'attachments' => []]);
        $this->post($base.'/note', ['body' => 'Internal', 'is_internal_note' => true, 'attachments' => []]);
        $this->post($base.'/status', ['status' => 'in_progress']);
        $this->post($base.'/priority', ['priority' => 'high']);
        $this->post($base.'/assign', ['agent_id' => $this->agent->getId()]);
        $this->post($base.'/tags', ['tag_ids' => [$this->vip->getId()]]);
        $this->post($base.'/department', ['department_id' => $this->billing->getId()]);
        $this->post($base.'/follow', []);
        $this->post($base.'/snooze', ['snoozed_until' => (new \DateTimeImmutable('+2 days'))->format(\DateTimeInterface::ATOM)]);

        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        $bodies = array_map(static fn (Reply $r): array => [$r->getBody(), $r->isInternalNote(), $r->getAuthorId()], $ticket->getReplies()->toArray());
        self::assertContains(['We are looking', false, $this->admin->getId()], $bodies);
        self::assertContains(['Internal', true, $this->admin->getId()], $bodies);
        self::assertSame('high', $ticket->getPriority());
        self::assertSame($this->agent->getId(), $ticket->getAssignedTo());
        self::assertSame(['vip'], array_map(static fn (Tag $t): string => $t->getName(), $ticket->getTags()->toArray()));
        self::assertSame($this->billing->getId(), $ticket->getDepartment()?->getId());
        self::assertSame(1, $em->getRepository(TicketFollower::class)->count(['ticketId' => $ticket->getId(), 'userId' => $this->admin->getId()]));
        self::assertNotNull($ticket->getSnoozedUntil());

        $this->post($base.'/unsnooze', []);
        $this->post($base.'/macro', ['macro_id' => $macro->getId()]);
        $note = $em->getRepository(Reply::class)->findOneBy(['body' => 'Internal']);
        $this->post($base.'/replies/'.$note->getId().'/pin', []);
        $this->post($base.'/tags', ['tag_ids' => []]);

        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertNull($ticket->getSnoozedUntil());
        self::assertSame('urgent', $ticket->getPriority());
        self::assertCount(0, $ticket->getTags());
        self::assertTrue($em->getRepository(Reply::class)->find($note->getId())->isPinned());

        $this->client->jsonRequest('POST', $base.'/presence');
        self::assertResponseIsSuccessful();
        self::assertSame(['viewers' => []], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testAgentDeskWritesUseTheAgentRoutes(): void
    {
        $ticket = $this->customerTicket('Agent writes');
        $base = '/support/agent/tickets/'.$ticket->getReference();
        $this->client->loginUser($this->agent);

        $this->post($base.'/note', ['body' => 'Agent note']);
        $this->post($base.'/priority', ['priority' => 'low']);
        $this->post($base.'/department', ['department_id' => $this->billing->getId()]);
        $this->post($base.'/follow', [], ['HTTP_REFERER' => $base]);
        self::assertResponseRedirects($base);

        $em = static::entityManager();
        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame('low', $ticket->getPriority());
        self::assertSame('Billing', $ticket->getDepartment()?->getName());
        self::assertSame('Agent note', $ticket->getReplies()->first()->getBody());
        self::assertTrue($ticket->getReplies()->first()->isInternalNote());
    }

    public function testBadInputGoesBackWithErrors(): void
    {
        $ticket = $this->customerTicket('Bad input');
        $this->client->loginUser($this->agent);
        $base = '/support/agent/tickets/'.$ticket->getReference();

        $this->post($base.'/status', ['status' => 'nonsense'], ['HTTP_REFERER' => $base]);
        self::assertResponseRedirects($base);
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');
        self::assertArrayHasKey('status', end($errors) ?: []);

        $this->post($base.'/reply', ['body' => '  ']);
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');
        self::assertArrayHasKey('body', end($errors) ?: []);
        self::assertCount(0, $this->tickets()->find($ticket->getReference())->getReplies());
    }

    public function testBulkActionsCountWhatChangedAndOnlyAdminsDelete(): void
    {
        $a = $this->customerTicket('Bulk A');
        $b = $this->customerTicket('Bulk B');
        $this->tickets()->changeStatus($b, Ticket::STATUS_CLOSED);

        $this->client->loginUser($this->agent);
        // B is closed, and closed cannot move to in_progress: skipped.
        $this->post('/support/agent/tickets/bulk', ['ticket_ids' => [$a->getId(), $b->getId()], 'action' => 'status', 'value' => 'in_progress'], ['HTTP_REFERER' => '/support/agent/tickets']);
        self::assertResponseRedirects('/support/agent/tickets');
        $flash = $this->client->getRequest()->getSession()->getFlashBag()->peek('success');
        self::assertSame('1 ticket updated.', end($flash));

        $this->post('/support/agent/tickets/bulk', ['ticket_ids' => [$a->getId(), $b->getId()], 'action' => 'priority', 'value' => 'urgent']);
        $this->post('/support/agent/tickets/bulk', ['ticket_ids' => [$a->getId()], 'action' => 'tags', 'value' => [$this->vip->getId()]]);
        $this->post('/support/agent/tickets/bulk', ['ticket_ids' => [$a->getId()], 'action' => 'delete']);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->admin);
        $this->post('/support/admin/tickets/bulk', ['ticket_ids' => [$b->getId()], 'action' => 'delete']);
        $this->post('/support/admin/tickets/bulk', ['ticket_ids' => [$a->getId()], 'action' => 'nonsense']);
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');
        self::assertArrayHasKey('action', end($errors) ?: []);

        $em = static::entityManager();
        $em->clear();
        $a = $em->getRepository(Ticket::class)->find($a->getId());
        $b = $em->getRepository(Ticket::class)->find($b->getId());
        self::assertSame('in_progress', $a->getStatus());
        self::assertSame('urgent', $a->getPriority());
        self::assertSame('urgent', $b->getPriority());
        self::assertCount(1, $a->getTags());
        self::assertNull($a->getDeletedAt());
        self::assertNotNull($b->getDeletedAt());
    }

    public function testCustomerSeesOnlyTheirOwnTicketsAndNoInternalNotes(): void
    {
        $mine = $this->customerTicket('Mine');
        $this->tickets()->addReply($mine, (int) $this->agent->getId(), 'Public answer', false, TestUser::class);
        $this->tickets()->addReply($mine, (int) $this->agent->getId(), 'Secret note', true, TestUser::class);
        $this->tickets()->changeStatus($mine, Ticket::STATUS_RESOLVED);
        $rating = (new SatisfactionRating())->setTicket($mine)->setRating(5)->setComment('Great');
        static::entityManager()->persist($rating);
        static::entityManager()->flush();
        $theirs = $this->guestTicket('Someone else');

        $this->client->loginUser($this->customer);
        $page = $this->visit('/support/customer/tickets');
        self::assertSame('Escalated/Customer/Index', $page['component']);
        self::assertSame(['Mine'], array_column($page['props']['tickets']['data'], 'subject'));
        self::assertSame([], $page['props']['filters']);
        foreach (['reference', 'subject', 'status', 'priority', 'requester', 'created_at', 'updated_at'] as $key) {
            self::assertArrayHasKey($key, $page['props']['tickets']['data'][0]);
        }

        $page = $this->visit('/support/customer/tickets/'.$mine->getReference());
        self::assertSame('Escalated/Customer/Show', $page['component']);
        $t = $page['props']['ticket'];
        self::assertSame(['reference', 'subject', 'description', 'status', 'priority', 'channel', 'department', 'attachments', 'replies', 'satisfaction_rating', 'resolved_at', 'closed_at', 'created_at', 'updated_at'], array_keys($t));
        self::assertSame(['Public answer'], array_column($t['replies'], 'body'));
        self::assertSame(['name' => 'agent@example.com'], $t['replies'][0]['author']);
        self::assertSame(['name' => 'Billing'], $t['department']);
        self::assertSame(['rating' => 5, 'comment' => 'Great'], $t['satisfaction_rating']);

        $this->client->request('GET', '/support/customer/tickets/'.$theirs->getReference());
        self::assertResponseStatusCodeSame(403);
    }

    public function testCustomerCreatesRepliesAndReopens(): void
    {
        $this->client->loginUser($this->customer);
        $page = $this->visit('/support/customer/tickets/create');
        self::assertSame('Escalated/Customer/Create', $page['component']);
        self::assertSame([['id' => $this->billing->getId(), 'name' => 'Billing']], $page['props']['departments']);
        self::assertSame(['low', 'medium', 'high', 'urgent', 'critical'], $page['props']['priorities']);

        $this->post('/support/customer/tickets', ['subject' => 'From the portal', 'description' => 'Help', 'priority' => 'high', 'department_id' => (string) $this->billing->getId(), 'ticket_type' => 'question', 'attachments' => []]);
        $ticket = static::entityManager()->getRepository(Ticket::class)->findOneBy(['subject' => 'From the portal']);
        self::assertNotNull($ticket);
        self::assertResponseRedirects('/support/customer/tickets/'.$ticket->getReference());
        self::assertSame($this->customer->getId(), $ticket->getRequesterId());
        self::assertSame(TestUser::class, $ticket->getRequesterClass());
        self::assertSame('high', $ticket->getPriority());

        $this->post('/support/customer/tickets', ['subject' => '', 'description' => '']);
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');
        self::assertSame(['subject', 'description'], array_keys(end($errors) ?: []));

        $this->post('/support/customer/tickets/'.$ticket->getReference().'/reply', ['body' => 'More detail']);
        $ticket = $this->tickets()->find($ticket->getReference());
        $this->tickets()->changeStatus($ticket, Ticket::STATUS_RESOLVED);
        $this->post('/support/customer/tickets/'.$ticket->getReference().'/rate', ['rating' => 4, 'comment' => 'ok']);
        $this->post('/support/customer/tickets/'.$ticket->getReference().'/reopen', []);

        $em = static::entityManager();
        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame('More detail', $ticket->getReplies()->first()->getBody());
        self::assertSame($this->customer->getId(), $ticket->getReplies()->first()->getAuthorId());
        self::assertSame(Ticket::STATUS_REOPENED, $ticket->getStatus());
        self::assertSame(4, $em->getRepository(SatisfactionRating::class)->findOneBy(['ticket' => $ticket])?->getRating());
    }

    public function testAgentDashboardGetsStatsAndRows(): void
    {
        $ticket = $this->customerTicket('Urgent and alone');
        $this->tickets()->changePriority($ticket, Ticket::PRIORITY_URGENT);

        $this->client->loginUser($this->agent);
        $page = $this->visit('/support/agent/dashboard');
        self::assertSame('Escalated/Agent/Dashboard', $page['component']);
        self::assertSame(['open', 'my_assigned', 'unassigned', 'sla_breached', 'resolved_today'], array_keys($page['props']['stats']));
        self::assertSame(1, $page['props']['stats']['unassigned']);
        self::assertSame('Urgent and alone', $page['props']['recentTickets'][0]['subject']);
        self::assertSame('Urgent and alone', $page['props']['needsAttention']['unassigned_urgent'][0]['subject']);
        self::assertSame([], $page['props']['needsAttention']['sla_breaching']);
        self::assertSame(['resolved_this_week' => 0], $page['props']['myPerformance']);
    }

    private function customerTicket(string $subject): Ticket
    {
        $ticket = $this->tickets()->create([
            'subject' => $subject,
            'description' => 'Body of '.$subject,
            'requester_id' => $this->customer->getId(),
            'requester_class' => TestUser::class,
            'department_id' => $this->billing->getId(),
        ]);
        static::entityManager()->refresh($ticket);

        return $ticket;
    }

    private function guestTicket(string $subject): Ticket
    {
        return $this->tickets()->create(['subject' => $subject, 'guest_name' => 'Gina', 'guest_email' => 'gina@example.com']);
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     */
    private function post(string $uri, array $body, array $server = []): void
    {
        $this->client->request('POST', $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
            ...$server,
        ], content: (string) json_encode($body));
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function row(array $rows, string $subject): array
    {
        foreach ($rows as $row) {
            if ($row['subject'] === $subject) {
                return $row;
            }
        }
        self::fail('no row for '.$subject);
    }

    private function tickets(): TicketService
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);

        return $service;
    }
}
