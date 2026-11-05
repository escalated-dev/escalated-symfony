<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentNotification;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Article;
use Escalated\Symfony\Entity\ArticleCategory;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\CustomerPresence;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\DepartmentAgent;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketRead;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Import\ImportContext;
use Escalated\Symfony\Service\AssignmentService;
use Escalated\Symfony\Service\ChatSessionService;
use Escalated\Symfony\Service\KnowledgeBaseSettings;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Widget\WidgetSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The agent panel's data (ADR 2026-11-05-agent-panel-data): the agent's own
 * status, knowledge-base search, per-agent unread markers, customer presence
 * and the notifications feed, through the real routes.
 */
final class AgentPanelDataTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $agent;
    private TestUser $other;
    private TestUser $customer;
    private int $seq = 0;

    private function boot(array $config = []): void
    {
        $this->client = static::clientWithSchema(['escalated' => [
            'user_class' => TestUser::class,
            'agent_inbox' => ['cache_seconds' => 0],
            ...$config,
        ]]);
        $em = static::entityManager();

        $this->agent = new TestUser('agent@example.com');
        $this->other = new TestUser('other@example.com');
        $this->customer = new TestUser('customer@example.com');
        foreach ([$this->agent, $this->other, $this->customer] as $user) {
            $em->persist($user);
        }
        $em->flush();
        foreach ([$this->agent, $this->other] as $user) {
            $em->persist((new AgentProfile())->setUserId((int) $user->getId()));
        }
        $em->flush();
        $this->client->loginUser($this->agent);
    }

    // ---- 3. The agent's own status ----------------------------------------

    public static function verbs(): iterable
    {
        yield 'PUT' => ['PUT'];
        yield 'POST' => ['POST'];
    }

    #[DataProvider('verbs')]
    public function testAnAgentSetsTheirOwnStatus(string $method): void
    {
        $this->boot();
        $response = $this->json($method, '/support/agent/status', ['status' => 'away']);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(['agent_status' => 'away'], $response);
        self::assertSame('away', $this->profile($this->agent)->getChatStatus());
    }

    public function testAStatusOutsideTheAllowedValuesIsRejected(): void
    {
        $this->boot();
        $this->json('PUT', '/support/agent/status', ['status' => 'busy']);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('offline', $this->profile($this->agent)->getChatStatus());
    }

    public function testTheStatusRouteIsRefusedToANonAgent(): void
    {
        $this->boot();
        $this->client->loginUser($this->customer);
        $this->json('PUT', '/support/agent/status', ['status' => 'online']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAnInertiaVisitGoesBack(): void
    {
        $this->boot();
        $this->client->request('PUT', '/support/agent/status', server: [
            'HTTP_X_INERTIA' => 'true',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_REFERER' => 'http://localhost/support/agent/tickets',
        ], content: json_encode(['status' => 'away'], \JSON_THROW_ON_ERROR));

        self::assertSame(303, $this->client->getResponse()->getStatusCode());
        self::assertSame('/support/agent/tickets', $this->client->getResponse()->headers->get('Location'));
    }

    public function testAgentsShareTheirStatusAndNotificationCount(): void
    {
        $this->boot();
        $shared = $this->shared('/support/agent/tickets');

        self::assertSame('offline', $shared['agent_status']);
        self::assertSame(['online', 'away', 'offline'], $shared['agent_statuses']);
        self::assertSame(['unread_count' => 0], $shared['notifications']);
    }

    // ---- 5. Knowledge-base search ---------------------------------------------

    private function article(array $values = []): Article
    {
        ++$this->seq;
        $article = (new Article())
            ->setTitle($values['title'] ?? 'Article '.$this->seq)
            ->setSlug($values['slug'] ?? 'article-'.$this->seq)
            ->setBody($values['body'] ?? '<p>Body</p>')
            ->setStatus($values['status'] ?? Article::STATUS_PUBLISHED)
            ->setCategory($values['category'] ?? null);
        $em = static::entityManager();
        $em->persist($article);
        $em->flush();
        if (isset($values['updated_at'])) {
            $em->getConnection()->update(
                $em->getClassMetadata(Article::class)->getTableName(),
                ['updated_at' => (new \DateTimeImmutable($values['updated_at']))->format('Y-m-d H:i:s')],
                ['id' => $article->getId()],
            );
            $em->clear();
        }

        return $article;
    }

    public function testKbSearchReturnsPublishedArticlesWithTheContractFields(): void
    {
        $this->boot();
        $category = (new ArticleCategory())->setName('Billing')->setSlug('billing');
        static::entityManager()->persist($category);
        $found = $this->article([
            'title' => 'Resetting your password',
            'slug' => 'resetting-your-password',
            'body' => '<p>Open <strong>Settings</strong> and choose Reset.</p>',
            'category' => $category,
        ]);

        $body = $this->json('GET', '/support/agent/kb/search?q=password');

        self::assertSame(['data' => [[
            'id' => $found->getId(),
            'title' => 'Resetting your password',
            'excerpt' => 'Open Settings and choose Reset.',
            'url' => 'http://localhost/support/kb/resetting-your-password',
            'category' => ['id' => $category->getId(), 'name' => 'Billing'],
            'updated_at' => $found->getUpdatedAt()->format(\DATE_ATOM),
        ]]], $body);
    }

    public function testKbSearchNeverReturnsDraftsAndIgnoresCase(): void
    {
        $this->boot();
        $this->article(['title' => 'Draft about refunds', 'status' => Article::STATUS_DRAFT]);
        $live = $this->article(['title' => 'Refunds Explained']);

        self::assertSame([$live->getId()], array_column($this->json('GET', '/support/agent/kb/search?q=REFUNDS')['data'], 'id'));
    }

    public function testKbSearchRanksTitleMatchesFirstThenTheMostRecentlyUpdated(): void
    {
        $this->boot();
        $bodyOnly = $this->article(['title' => 'Getting started', 'body' => 'How invoices work', 'updated_at' => '-1 hour']);
        $older = $this->article(['title' => 'Invoices (old)', 'updated_at' => '-3 days']);
        $newer = $this->article(['title' => 'Invoices (new)', 'updated_at' => '-2 days']);

        self::assertSame(
            [$newer->getId(), $older->getId(), $bodyOnly->getId()],
            array_column($this->json('GET', '/support/agent/kb/search?q=invoices')['data'], 'id'),
        );
    }

    public static function wildcards(): iterable
    {
        yield '%%' => ['%25%25'];
        yield '__' => ['__'];
        yield '0%' => ['0%25'];
        yield 'a_b' => ['a_b'];
    }

    #[DataProvider('wildcards')]
    public function testKbSearchTakesLikeWildcardsLiterally(string $q): void
    {
        $this->boot();
        $this->article(['title' => 'Plain title', 'body' => 'Nothing special']);
        $literal = $this->article(['title' => 'Codes: 100%% off, a_b and x__y', 'body' => 'x']);

        self::assertSame([$literal->getId()], array_column($this->json('GET', '/support/agent/kb/search?q='.$q)['data'], 'id'));
    }

    public function testKbSearchCapsResultsAndValidatesTheQuery(): void
    {
        $this->boot();
        for ($i = 0; $i < 25; ++$i) {
            $this->article(['title' => 'Shipping guide '.$i]);
        }
        self::assertCount(10, $this->json('GET', '/support/agent/kb/search?q=shipping')['data']);
        self::assertCount(20, $this->json('GET', '/support/agent/kb/search?q=shipping&limit=20')['data']);
        foreach (['q=shipping&limit=21', 'q=', 'q=a', 'q='.str_repeat('a', 201), ''] as $query) {
            $this->json('GET', '/support/agent/kb/search?'.$query);
            self::assertSame(422, $this->client->getResponse()->getStatusCode(), $query);
        }
    }

    public function testKbSearchAnswers404WhileTheKnowledgeBaseIsOff(): void
    {
        $this->boot();
        $settings = static::getContainer()->get(KnowledgeBaseSettings::class);
        \assert($settings instanceof KnowledgeBaseSettings);
        $settings->setEnabled(false);

        $this->json('GET', '/support/agent/kb/search?q=password');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    // ---- 1. Unread tickets ----------------------------------------------------------

    public function testANewTicketIsUnreadUntilTheAgentOpensIt(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        self::assertTrue($this->rows()[$ticket->getReference()]['is_unread']);

        self::assertFalse($this->visit('/support/agent/tickets/'.$ticket->getReference())['props']['ticket']['is_unread']);
        self::assertFalse($this->rows()[$ticket->getReference()]['is_unread']);

        // Per agent: the other agent has not opened it.
        $this->client->loginUser($this->other);
        self::assertTrue($this->rows()[$ticket->getReference()]['is_unread']);
    }

    public function testACustomerReplyMakesAReadTicketUnreadAgain(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        $this->visit('/support/agent/tickets/'.$ticket->getReference());
        $this->backdateReads();

        $this->tickets()->addReply($this->fresh($ticket), (int) $this->customer->getId(), 'Any news?', false, TestUser::class);

        self::assertTrue($this->rows()[$ticket->getReference()]['is_unread']);
    }

    public function testAgentRepliesNotesAndSystemRepliesDoNotMakeATicketUnread(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        $this->visit('/support/agent/tickets/'.$ticket->getReference());
        $this->backdateReads();

        $fresh = $this->fresh($ticket);
        $this->tickets()->addReply($fresh, (int) $this->agent->getId(), 'From me', false, TestUser::class);
        $this->tickets()->addReply($fresh, (int) $this->other->getId(), 'From a colleague', false, TestUser::class);
        $this->tickets()->addReply($fresh, (int) $this->customer->getId(), 'A note', true, TestUser::class);
        $em = static::entityManager();
        $reply = (new Reply())->setTicket($fresh)->setBody('Workflow')->setIsInternalNote(false)->setType('reply')->setMetadata(['workflow_id' => true]);
        $em->persist($reply);
        $em->flush();

        self::assertFalse($this->rows()[$ticket->getReference()]['is_unread']);
    }

    public function testImportedTicketsStartRead(): void
    {
        $this->boot();
        $ticket = ImportContext::suppress(fn (): Ticket => $this->ticket());

        self::assertFalse($this->rows()[$ticket->getReference()]['is_unread']);
    }

    public function testTicketsAreMarkedReadAndUnreadThroughTheAgentRoutes(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        $path = '/support/agent/tickets/'.$ticket->getReference();

        self::assertSame(['is_unread' => false], $this->json('POST', $path.'/read'));
        self::assertFalse($this->rows()[$ticket->getReference()]['is_unread']);

        self::assertSame(['is_unread' => true], $this->json('POST', $path.'/unread'));
        self::assertTrue($this->rows()[$ticket->getReference()]['is_unread']);
    }

    public function testTicketsAreMarkedReadAndUnreadInBulk(): void
    {
        $this->boot();
        $a = $this->ticket();
        $b = $this->ticket();

        $this->json('POST', '/support/agent/tickets/bulk', ['ticket_ids' => [$a->getId(), $b->getId()], 'action' => 'mark_read']);
        $rows = $this->rows();
        self::assertSame([false, false], [$rows[$a->getReference()]['is_unread'], $rows[$b->getReference()]['is_unread']]);

        $this->json('POST', '/support/agent/tickets/bulk', ['ticket_ids' => [$a->getId()], 'action' => 'mark_unread']);
        $rows = $this->rows();
        self::assertSame([true, false], [$rows[$a->getReference()]['is_unread'], $rows[$b->getReference()]['is_unread']]);
    }

    public function testInboxUnreadCountsUnreadOpenTicketsPerQueue(): void
    {
        $this->boot();
        $me = (int) $this->agent->getId();
        $mine = $this->ticket(['assigned_to' => $me]);
        $this->ticket(['assigned_to' => $me]);
        $unassigned = $this->ticket();
        $this->ticket(['status' => 'resolved']);
        $this->visit('/support/agent/tickets/'.$mine->getReference());
        $this->visit('/support/agent/tickets/'.$unassigned->getReference());

        self::assertSame(['mine' => 1, 'unassigned' => 0, 'all' => 1], $this->visit('/support/agent/tickets')['props']['inbox']['unread']);
    }

    public function testOnlyTicketsTheAgentMaySeeAreCountedAndMarked(): void
    {
        $this->boot(['visibility' => 'department']);
        $em = static::entityManager();
        $support = (new Department())->setName('Support')->setSlug('support');
        $sales = (new Department())->setName('Sales')->setSlug('sales');
        $em->persist($support);
        $em->persist($sales);
        $em->flush();
        $em->persist(new DepartmentAgent($support, $this->agent->getId()));
        $em->flush();
        $visible = $this->ticket(['department' => $support]);
        $hidden = $this->ticket(['department' => $sales]);

        self::assertSame(1, $this->visit('/support/agent/tickets')['props']['inbox']['unread']['all']);

        $this->json('POST', '/support/agent/tickets/'.$hidden->getReference().'/read');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->json('POST', '/support/agent/tickets/bulk', ['ticket_ids' => [$hidden->getId(), $visible->getId()], 'action' => 'mark_read']);

        $marked = array_map(static fn (TicketRead $r): ?int => $r->getTicket()->getId(), static::entityManager()->getRepository(TicketRead::class)->findAll());
        self::assertSame([$visible->getId()], $marked);
    }

    // ---- 2. Customer presence ------------------------------------------------------------

    private function visitPortal(): void
    {
        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/support/customer/tickets', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $this->client->loginUser($this->agent);
    }

    public function testACustomerIsOnlineWhileThePortalSawThemWithinTheWindow(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        $row = $this->rows()[$ticket->getReference()];
        self::assertSame([false, null], [$row['requester_online'], $row['requester_last_seen_at']]);

        $this->visitPortal();
        $row = $this->rows()[$ticket->getReference()];
        self::assertTrue($row['requester_online']);
        self::assertIsString($row['requester_last_seen_at']);

        $this->backdatePresence('-121 seconds');
        self::assertFalse($this->rows()[$ticket->getReference()]['requester_online']);
    }

    public function testPresenceIsWrittenAtMostOnceEvery30Seconds(): void
    {
        $this->boot();
        $this->visitPortal();
        $first = $this->presence()->getLastSeenAt()->getTimestamp();
        $this->visitPortal();
        self::assertSame($first, $this->presence()->getLastSeenAt()->getTimestamp());

        $this->backdatePresence('-31 seconds');
        $this->visitPortal();
        self::assertGreaterThan(time() - 10, $this->presence()->getLastSeenAt()->getTimestamp());
        self::assertCount(1, static::entityManager()->getRepository(CustomerPresence::class)->findAll());
    }

    public function testAWidgetChatGuestIsRecorded(): void
    {
        $this->boot();
        $widget = static::getContainer()->get(WidgetSettings::class);
        \assert($widget instanceof WidgetSettings);
        $widget->setEnabled(true);
        $ticket = $this->ticket(['requester' => false]);
        $token = '0f6b4c2e-9d7a-4a51-8c3e-2b1f0e6d7a90';
        $session = (new ChatSession())->setTicket($this->fresh($ticket))->setStatus('active')
            ->setVisitorTokenHash(ChatSessionService::hashVisitorToken($token))
            ->setVisitorTokenExpiresAt(new \DateTimeImmutable('+1 hour'));
        static::entityManager()->persist($session);
        static::entityManager()->flush();

        $this->client->request('GET', '/support/widget/api/chat/'.$token.'/messages', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('requester_online', (string) $this->client->getResponse()->getContent());

        self::assertTrue($this->rows()[$ticket->getReference()]['requester_online']);
    }

    public function testPresenceIsSentOnTheAgentTicketPageAndNeverToCustomers(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        $this->visitPortal();
        self::assertTrue($this->visit('/support/agent/tickets/'.$ticket->getReference())['props']['ticket']['requester_online']);

        $this->client->loginUser($this->customer);
        $this->client->request('GET', '/support/customer/tickets/'.$ticket->getReference(), server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('requester_online', $content);
        self::assertStringNotContainsString('requester_last_seen_at', $content);
    }

    public function testStaffBrowsingTheCustomerPortalAreNotRecorded(): void
    {
        $this->boot();
        $this->client->request('GET', '/support/customer/tickets', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertCount(0, static::entityManager()->getRepository(CustomerPresence::class)->findAll());
    }

    // ---- 4. Notifications --------------------------------------------------------------------

    private function assign(Ticket $ticket, TestUser $agent, TestUser $causer): void
    {
        $service = static::getContainer()->get(AssignmentService::class);
        \assert($service instanceof AssignmentService);
        $service->assign($this->fresh($ticket), (int) $agent->getId(), (int) $causer->getId());
    }

    public function testATicketAssignmentIsListedWithTheContractShape(): void
    {
        $this->boot();
        $ticket = $this->ticket(['subject' => 'Printer on fire']);
        $this->assign($ticket, $this->agent, $this->other);
        $row = static::entityManager()->getRepository(AgentNotification::class)->findOneBy([]);
        \assert($row instanceof AgentNotification);

        self::assertSame([
            'data' => [[
                'id' => $row->getId(),
                'type' => 'ticket_assigned',
                'created_at' => $row->getCreatedAt()->format(\DATE_ATOM),
                'read_at' => null,
                'title' => $ticket->getReference().' was assigned to you',
                'body' => 'Printer on fire',
                'ticket' => ['id' => $ticket->getId(), 'reference' => $ticket->getReference(), 'subject' => 'Printer on fire'],
                'url' => '/support/agent/tickets/'.$ticket->getReference(),
            ]],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1],
            'unread_count' => 1,
        ], $this->json('GET', '/support/agent/notifications'));
    }

    public function testAssigningYourselfNotifiesNobody(): void
    {
        $this->boot();
        $this->assign($this->ticket(), $this->agent, $this->agent);

        self::assertSame([], $this->json('GET', '/support/agent/notifications')['data']);
    }

    public function testTheAssigneeHearsOfCustomerRepliesNotOfAnotherAgents(): void
    {
        $this->boot();
        $ticket = $this->ticket(['assigned_to' => (int) $this->agent->getId()]);
        $this->tickets()->addReply($this->fresh($ticket), (int) $this->other->getId(), 'From a colleague', false, TestUser::class);
        self::assertSame([], $this->json('GET', '/support/agent/notifications')['data']);

        $this->tickets()->addReply($this->fresh($ticket), (int) $this->customer->getId(), 'From the customer', false, TestUser::class);
        $items = $this->json('GET', '/support/agent/notifications')['data'];
        self::assertSame([['ticket_reply', 'New reply on '.$ticket->getReference()]], array_map(static fn (array $i): array => [$i['type'], $i['title']], $items));
    }

    public function testSlaBreachesAndChatTransfersAreNotified(): void
    {
        $this->boot();
        $ticket = $this->fresh($this->ticket(['assigned_to' => (int) $this->agent->getId()]));
        $events = static::getContainer()->get(EventDispatcherInterface::class);
        \assert($events instanceof EventDispatcherInterface);
        $events->dispatch(new TicketWorkflowEvent('sla.breached', $ticket, ['breach_type' => 'resolution']));
        $events->dispatch(new TicketWorkflowEvent('chat.transferred', $ticket, ['chat_session_id' => 7, 'from_agent_id' => (int) $this->other->getId(), 'to_agent_id' => (int) $this->agent->getId()]));

        $types = array_column($this->json('GET', '/support/agent/notifications')['data'], 'type');
        sort($types);
        self::assertSame(['chat_transferred', 'sla_breached'], $types);
    }

    public function testTheFeedPagesNewestFirstFiltersAndMarksRead(): void
    {
        $this->boot();
        for ($i = 1; $i <= 3; ++$i) {
            $this->assign($this->ticket(['subject' => 'Ticket '.$i]), $this->agent, $this->other);
        }
        $em = static::entityManager();
        foreach ($em->getRepository(AgentNotification::class)->findAll() as $row) {
            $em->getConnection()->update(
                $em->getClassMetadata(AgentNotification::class)->getTableName(),
                ['created_at' => (new \DateTimeImmutable(sprintf('-%d minutes', 10 - (int) $row->getData()['ticket_id'])))->format('Y-m-d H:i:s')],
                ['id' => $row->getId()],
            );
        }
        $em->clear();

        $first = $this->json('GET', '/support/agent/notifications?per_page=2');
        self::assertSame(['Ticket 3', 'Ticket 2'], array_column($first['data'], 'body'));
        self::assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 2, 'total' => 3], $first['meta']);

        self::assertSame(['unread_count' => 2], $this->json('POST', '/support/agent/notifications/'.$first['data'][0]['id'].'/read'));
        self::assertSame(['Ticket 2', 'Ticket 1'], array_column($this->json('GET', '/support/agent/notifications?unread=1')['data'], 'body'));

        $this->json('GET', '/support/agent/notifications?per_page=51');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        self::assertSame(['unread_count' => 0], $this->json('POST', '/support/agent/notifications/read-all'));
        self::assertSame(0, $this->json('GET', '/support/agent/notifications')['unread_count']);
    }

    public function testAnotherAgentsNotificationCannotBeMarkedRead(): void
    {
        $this->boot();
        $this->assign($this->ticket(), $this->other, $this->agent);
        $row = static::entityManager()->getRepository(AgentNotification::class)->findOneBy([]);
        \assert($row instanceof AgentNotification);

        $this->json('POST', '/support/agent/notifications/'.$row->getId().'/read');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testATicketTheAgentCanNoLongerViewIsRedacted(): void
    {
        $this->boot(['visibility' => 'department']);
        $em = static::entityManager();
        $support = (new Department())->setName('Support')->setSlug('support');
        $billing = (new Department())->setName('Billing')->setSlug('billing');
        $em->persist($support);
        $em->persist($billing);
        $em->flush();
        $em->persist(new DepartmentAgent($support, $this->agent->getId()));
        $em->flush();
        $ticket = $this->ticket(['department' => $support, 'subject' => 'Secret subject']);
        $em->persist(new AgentNotification((int) $this->agent->getId(), 'ticket_assigned', ['ticket_id' => $ticket->getId(), 'reference' => $ticket->getReference()]));
        $em->flush();
        $em->getConnection()->update($em->getClassMetadata(Ticket::class)->getTableName(), ['department_id' => $billing->getId()], ['id' => $ticket->getId()]);
        $em->clear();

        $item = $this->json('GET', '/support/agent/notifications')['data'][0];
        self::assertSame('A ticket you can no longer view', $item['title']);
        self::assertSame([null, null, null], [$item['body'], $item['ticket'], $item['url']]);
        self::assertStringNotContainsString('Secret subject', json_encode($item, \JSON_THROW_ON_ERROR));
    }

    public function testTheFeedIsOffWhenSwitchedOff(): void
    {
        $this->boot(['agent_notifications' => false]);
        $this->json('GET', '/support/agent/notifications');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $this->json('POST', '/support/agent/notifications/read-all');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertArrayNotHasKey('notifications', $this->shared('/support/agent/tickets'));
    }

    // ---- Helpers ----------------------------------------------------------------------------------

    private function ticket(array $attributes = []): Ticket
    {
        ++$this->seq;
        $ticket = (new Ticket())
            ->setSubject($attributes['subject'] ?? 'Panel '.$this->seq)
            ->setDescription('Details')
            ->setStatus($attributes['status'] ?? 'open')
            ->setPriority('low')
            ->setAssignedTo($attributes['assigned_to'] ?? null)
            ->setDepartment($attributes['department'] ?? null);
        if ('resolved' === ($attributes['status'] ?? null)) {
            $ticket->setResolvedAt(new \DateTimeImmutable());
        }
        if (false !== ($attributes['requester'] ?? true)) {
            $ticket->setRequesterId((int) $this->customer->getId())->setRequesterClass(TestUser::class);
        }
        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();
        $ticket->setReference($ticket->generateReference('PNL'));
        $em->flush();

        return $ticket;
    }

    private function fresh(Ticket $ticket): Ticket
    {
        $found = static::entityManager()->find(Ticket::class, $ticket->getId());
        \assert($found instanceof Ticket);

        return $found;
    }

    private function tickets(): TicketService
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);

        return $service;
    }

    private function profile(TestUser $user): AgentProfile
    {
        static::entityManager()->clear();
        $profile = static::entityManager()->getRepository(AgentProfile::class)->findOneBy(['userId' => $user->getId()]);
        \assert($profile instanceof AgentProfile);

        return $profile;
    }

    private function presence(): CustomerPresence
    {
        static::entityManager()->clear();
        $row = static::entityManager()->getRepository(CustomerPresence::class)->findOneBy([]);
        \assert($row instanceof CustomerPresence);

        return $row;
    }

    /** Move what happened so far into the past: activity two minutes ago, the read a minute ago. */
    private function backdateReads(): void
    {
        $em = static::entityManager();
        $conn = $em->getConnection();
        $conn->executeStatement(sprintf('UPDATE %s SET last_read_at = ? WHERE last_read_at IS NOT NULL', $em->getClassMetadata(TicketRead::class)->getTableName()), [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s')]);
        $conn->executeStatement(sprintf('UPDATE %s SET last_customer_activity_at = ? WHERE last_customer_activity_at IS NOT NULL', $em->getClassMetadata(Ticket::class)->getTableName()), [(new \DateTimeImmutable('-2 minutes'))->format('Y-m-d H:i:s')]);
        $em->clear();
    }

    private function backdatePresence(string $when): void
    {
        $em = static::entityManager();
        $em->getConnection()->executeStatement(sprintf('UPDATE %s SET last_seen_at = ?', $em->getClassMetadata(CustomerPresence::class)->getTableName()), [(new \DateTimeImmutable($when))->format('Y-m-d H:i:s')]);
        $em->clear();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rows(): array
    {
        return array_column($this->visit('/support/agent/tickets')['props']['tickets']['data'], null, 'reference');
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(string $uri): array
    {
        $this->visit($uri);
        $shared = $this->client->getRequest()->attributes->get('_escalated_shared');
        self::assertIsArray($shared);

        return $shared;
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
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function json(string $method, string $uri, ?array $body = null): array
    {
        $this->client->request($method, $uri, server: [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
