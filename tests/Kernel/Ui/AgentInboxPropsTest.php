<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\DepartmentAgent;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SavedView;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The agent panel's optional `inbox` and `queue` props, and the list filters
 * its rail, views and stat strip link to (shared frontend docs/agent-panel.md),
 * as escalated-laravel sends them.
 *
 * Every number here must be what the agent would get by opening the list it
 * labels, so the tests check counts against the list and against visibility.
 */
final class AgentInboxPropsTest extends EscalatedWebTestCase
{
    private const BUILT_IN_VIEW_IDS = ['urgent', 'sla_at_risk', 'waiting_on_me', 'waiting_on_customer', 'recently_updated'];

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

    public function testSendsInboxCountsBuiltInViewsStatsAndTheQueue(): void
    {
        $this->boot();
        $me = (int) $this->agent->getId();
        $this->ticket(['assigned_to' => $me, 'priority' => 'urgent']);
        $this->ticket(['assigned_to' => $me, 'status' => 'waiting_on_customer']);
        $this->ticket(['assigned_to' => $me, 'status' => 'resolved', 'resolved_at' => new \DateTimeImmutable()]);
        $this->ticket(['assigned_to' => $me, 'status' => 'resolved', 'resolved_at' => new \DateTimeImmutable('-2 days')]);
        $this->ticket(['assigned_to' => $me, 'snoozed_until' => new \DateTimeImmutable('+1 day')]);
        $this->ticket();
        $this->ticket(['status' => 'closed']);
        $this->ticket(['assigned_to' => (int) $this->other->getId()]);

        $page = $this->visit('/support/agent/tickets');
        self::assertSame('Escalated/Agent/TicketIndex', $page['component']);

        $inbox = $page['props']['inbox'];
        self::assertSame(['counts', 'unread', 'views', 'stats'], array_keys($inbox));
        // Unresolved, unsnoozed tickets in each queue.
        self::assertSame(['mine' => 2, 'unassigned' => 1, 'all' => 4], $inbox['counts']);
        self::assertSame(self::BUILT_IN_VIEW_IDS, array_column($inbox['views'], 'id'));
        self::assertSame(['id' => 'urgent', 'name' => 'Urgent', 'count' => 1, 'filters' => ['priority' => 'urgent', 'open' => 1]], $inbox['views'][0]);
        // The "all" queue: three tickets with status open, one urgent, one resolved today.
        self::assertSame(['open' => 3, 'urgent' => 1, 'sla_at_risk' => 0, 'resolved_today' => 1], $inbox['stats']);
        self::assertSame(['key' => 'all', 'title' => 'All tickets'], $page['props']['queue']);

        $views = array_column($inbox['views'], null, 'id');
        self::assertSame(1, $views['waiting_on_customer']['count']);
        self::assertSame(['assigned_to' => $me, 'status' => 'waiting_on_customer'], $views['waiting_on_customer']['filters']);
        self::assertSame(['assigned_to' => $me, 'status' => 'waiting_on_agent'], $views['waiting_on_me']['filters']);
        self::assertSame(['sla_at_risk' => 1], $views['sla_at_risk']['filters']);
        self::assertSame(['open' => 1, 'updated_within_hours' => 24, 'sort_by' => 'updated_at'], $views['recently_updated']['filters']);
        self::assertSame(4, $views['recently_updated']['count']);
    }

    public static function queries(): iterable
    {
        yield 'mine' => ['?assigned_to={me}', 'mine', 'My inbox', [1, 1, 0, 1]];
        yield 'mine, by alias' => ['?assigned=me', 'mine', 'My inbox', [1, 1, 0, 1]];
        yield 'mine with a stat tile on' => ['?assigned_to={me}&priority=urgent', 'mine', 'My inbox', [1, 1, 0, 1]];
        yield 'unassigned' => ['?unassigned=1', 'unassigned', 'Unassigned', [2, 1, 0, 1]];
        yield 'all' => ['', 'all', 'All tickets', [3, 2, 0, 2]];
        yield 'a built-in view by its filters' => ['?priority=urgent&open=1', 'view:urgent', 'Urgent', [2, 2, 0, 0]];
        yield 'a built-in view by id' => ['?view=urgent', 'view:urgent', 'Urgent', [2, 2, 0, 0]];
    }

    /**
     * @param list<int> $stats
     */
    #[DataProvider('queries')]
    public function testNamesAndScopesTheQueueFromTheListQuery(string $query, string $key, string $title, array $stats): void
    {
        $this->boot();
        $me = (int) $this->agent->getId();
        $this->ticket(['assigned_to' => $me, 'priority' => 'urgent']);
        $this->ticket(['assigned_to' => $me, 'status' => 'resolved', 'resolved_at' => new \DateTimeImmutable()]);
        $this->ticket();
        $this->ticket(['status' => 'resolved', 'resolved_at' => new \DateTimeImmutable()]);
        $this->ticket(['priority' => 'urgent']);

        $page = $this->visit('/support/agent/tickets'.str_replace('{me}', (string) $me, $query));

        self::assertSame(['key' => $key, 'title' => $title], $page['props']['queue']);
        self::assertSame(array_combine(['open', 'urgent', 'sla_at_risk', 'resolved_today'], $stats), $page['props']['inbox']['stats']);
    }

    public function testListsOwnAndSharedSavedViewsButNotOtherAgentsPrivateViews(): void
    {
        $this->boot();
        $mine = $this->view((int) $this->agent->getId(), 'High priority', ['priority' => 'high'], 1);
        $shared = $this->view((int) $this->other->getId(), 'Waiting', ['status' => 'waiting_on_agent'], 2, true);
        $this->view((int) $this->other->getId(), 'Private', ['priority' => 'low'], 0);

        $this->ticket(['priority' => 'high']);
        $this->ticket(['priority' => 'high']);
        $this->ticket(['priority' => 'high', 'status' => 'closed']);
        $this->ticket(['status' => 'waiting_on_agent']);

        $views = $this->visit('/support/agent/tickets')['props']['inbox']['views'];
        $saved = array_values(array_filter($views, static fn (array $v): bool => !\in_array($v['id'], self::BUILT_IN_VIEW_IDS, true)));

        self::assertSame([
            ['id' => $mine->getId(), 'name' => 'High priority', 'count' => 2, 'filters' => ['priority' => 'high']],
            ['id' => $shared->getId(), 'name' => 'Waiting', 'count' => 1, 'filters' => ['status' => 'waiting_on_agent']],
        ], $saved);
    }

    public static function selections(): iterable
    {
        yield 'by id' => ['id'];
        yield 'by filters' => ['filters'];
    }

    #[DataProvider('selections')]
    public function testSelectsASavedViewAsTheQueueAndFiltersTheListWithIt(string $by): void
    {
        $this->boot();
        $view = $this->view((int) $this->agent->getId(), 'High priority', ['priority' => 'high'], 1);
        $high = $this->ticket(['priority' => 'high']);
        $this->ticket(['priority' => 'low']);

        $page = $this->visit('/support/agent/tickets'.('id' === $by ? '?view='.$view->getId() : '?priority=high'));

        self::assertSame(['key' => 'view:'.$view->getId(), 'title' => 'High priority'], $page['props']['queue']);
        self::assertSame([$high->getId()], array_column($page['props']['tickets']['data'], 'id'));
    }

    public function testDoesNotLetAnAgentSelectAnotherAgentsPrivateView(): void
    {
        $this->boot();
        $private = $this->view((int) $this->other->getId(), 'Private', ['priority' => 'high'], 1);
        $this->ticket(['priority' => 'high']);
        $this->ticket(['priority' => 'low']);

        $page = $this->visit('/support/agent/tickets?view='.$private->getId());

        self::assertSame(['key' => 'all', 'title' => 'All tickets'], $page['props']['queue']);
        self::assertSame(2, $page['props']['tickets']['total']);
    }

    public function testGivesEveryBuiltInViewTheCountItsOwnListShows(): void
    {
        $this->boot();
        $me = (int) $this->agent->getId();
        $this->ticket(['assigned_to' => $me, 'priority' => 'urgent']);
        $this->ticket(['priority' => 'urgent', 'status' => 'closed']);
        $this->ticket(['assigned_to' => $me, 'status' => 'waiting_on_agent']);
        $this->ticket(['assigned_to' => $me, 'status' => 'waiting_on_customer']);
        $this->ticket(['sla_first_response_breached' => true]);
        $this->ticket(['resolution_due_at' => new \DateTimeImmutable('+30 minutes')]);
        $stale = $this->ticket();
        $this->backdate($stale, '-3 days');

        $views = $this->visit('/support/agent/tickets')['props']['inbox']['views'];

        foreach ($views as $view) {
            $total = $this->visit('/support/agent/tickets?'.http_build_query($view['filters']))['props']['tickets']['total'];
            self::assertSame($total, $view['count'], sprintf('view %s counts %d but its list shows %d', $view['id'], $view['count'], $total));
        }

        self::assertSame(
            ['urgent' => 1, 'sla_at_risk' => 2, 'waiting_on_me' => 1, 'waiting_on_customer' => 1, 'recently_updated' => 5],
            array_column($views, 'count', 'id'),
        );
    }

    public function testCountsOnlyTicketsTheAgentMaySee(): void
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

        $me = (int) $this->agent->getId();
        $this->ticket(['department' => $support, 'assigned_to' => $me, 'priority' => 'urgent']);
        $this->ticket(['department' => $support]);
        $this->ticket(['department' => $billing]);
        $this->ticket(['department' => $billing, 'priority' => 'urgent']);
        $this->ticket(['department' => $billing, 'status' => 'resolved', 'resolved_at' => new \DateTimeImmutable()]);

        $inbox = $this->visit('/support/agent/tickets')['props']['inbox'];

        self::assertSame(['mine' => 1, 'unassigned' => 1, 'all' => 2], $inbox['counts']);
        self::assertSame(['open' => 2, 'urgent' => 1, 'sla_at_risk' => 0, 'resolved_today' => 0], $inbox['stats']);
        self::assertSame(1, array_column($inbox['views'], 'count', 'id')['urgent']);
    }

    public function testFiltersTheListToTicketsWhoseSlaIsBreachedOrDueWithinTheHour(): void
    {
        $this->boot();
        $breached = $this->ticket(['sla_resolution_breached' => true]);
        $responseDue = $this->ticket(['first_response_due_at' => new \DateTimeImmutable('+20 minutes')]);
        $resolutionDue = $this->ticket(['resolution_due_at' => new \DateTimeImmutable('+50 minutes')]);
        $this->ticket(['first_response_due_at' => new \DateTimeImmutable('+20 minutes'), 'first_response_at' => new \DateTimeImmutable('-1 minute')]);
        $this->ticket(['resolution_due_at' => new \DateTimeImmutable('+3 hours')]);
        $this->ticket(['sla_resolution_breached' => true, 'status' => 'resolved', 'resolved_at' => new \DateTimeImmutable()]);
        $this->ticket();

        $page = $this->visit('/support/agent/tickets?sla_at_risk=1');
        $ids = array_column($page['props']['tickets']['data'], 'id');
        sort($ids);

        self::assertSame([$breached->getId(), $responseDue->getId(), $resolutionDue->getId()], $ids);
        self::assertSame(3, $page['props']['inbox']['stats']['sla_at_risk']);
    }

    public function testFiltersTheListToTicketsResolvedToday(): void
    {
        $this->boot();
        $today = $this->ticket(['status' => 'resolved', 'resolved_at' => new \DateTimeImmutable('today +1 minute')]);
        $this->ticket(['status' => 'resolved', 'resolved_at' => new \DateTimeImmutable('yesterday')]);
        $this->ticket();

        $page = $this->visit('/support/agent/tickets?status=resolved&resolved_today=1');

        self::assertSame([$today->getId()], array_column($page['props']['tickets']['data'], 'id'));
    }

    public function testFiltersTheListToTicketsUpdatedWithinTheGivenHours(): void
    {
        $this->boot();
        $fresh = $this->ticket();
        $this->backdate($this->ticket(), '-2 days');

        $page = $this->visit('/support/agent/tickets?updated_within_hours=24');

        self::assertSame([$fresh->getId()], array_column($page['props']['tickets']['data'], 'id'));
    }

    public function testFiltersTheListToUnresolvedTicketsWithOpen(): void
    {
        $this->boot();
        $open = $this->ticket();
        $this->ticket(['status' => 'resolved', 'resolved_at' => new \DateTimeImmutable()]);
        $this->ticket(['status' => 'closed']);

        $page = $this->visit('/support/agent/tickets?open=1');

        self::assertSame([$open->getId()], array_column($page['props']['tickets']['data'], 'id'));
    }

    public function testFiltersTheListToTheAgentWithAssignedMe(): void
    {
        $this->boot();
        $mine = $this->ticket(['assigned_to' => (int) $this->agent->getId()]);
        $this->ticket(['assigned_to' => (int) $this->other->getId()]);
        $this->ticket();

        $page = $this->visit('/support/agent/tickets?assigned=me');

        self::assertSame([$mine->getId()], array_column($page['props']['tickets']['data'], 'id'));
    }

    public static function malformed(): iterable
    {
        yield [['sla_at_risk' => 'soon']];
        yield [['resolved_today' => 'maybe']];
        yield [['open' => 'perhaps']];
        yield [['updated_within_hours' => '0']];
        yield [['updated_within_hours' => 'a day']];
        yield [['updated_within_hours' => '9000']];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('malformed')]
    public function testSendsMalformedValuesForTheNewFiltersBackWithErrors(array $query): void
    {
        // escalated-laravel's $request->validate(): a page visit goes back with
        // the errors for the next page's `errors` prop, never a raw 422 (which
        // the Inertia client shows as an error modal).
        $this->boot();

        $this->client->request('GET', '/support/agent/tickets?'.http_build_query($query), server: [
            'HTTP_X_INERTIA' => 'true',
            'HTTP_REFERER' => 'http://localhost/support/agent/tickets?assigned=me',
        ]);

        self::assertResponseRedirects('/support/agent/tickets?assigned=me');
        $errors = $this->visit('/support/agent/tickets?assigned=me')['props']['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertSame(array_keys($query), array_keys($errors));
        self::assertContainsOnly('string', $errors);
        self::assertArrayNotHasKey('errors', $this->visit('/support/agent/tickets')['props']);
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('malformed')]
    public function testAnswersAJsonClientsMalformedFilterWithA422(array $query): void
    {
        $this->boot();

        $this->client->request('GET', '/support/agent/tickets?'.http_build_query($query), server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(array_keys($query), array_keys($body['errors']));
        self::assertIsString($body['message']);
    }

    public function testSendsAMalformedFilterWithNoRefererBackToTheList(): void
    {
        $this->boot();

        $this->client->request('GET', '/support/agent/tickets?sla_at_risk=soon', server: ['HTTP_ACCEPT' => 'text/html']);

        self::assertResponseRedirects('/support/agent/tickets');
    }

    public function testPreviewsTheLatestCustomerVisibleMessageNeverAnInternalNote(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        $this->reply($ticket, 'Customer-visible answer', false, '-5 minutes');
        $this->reply($ticket, 'Secret internal note', true, 'now');

        $row = $this->visit('/support/agent/tickets')['props']['tickets']['data'][0];

        self::assertSame('Customer-visible answer', $row['latest_reply']['body']);
        self::assertFalse($row['latest_reply']['is_internal_note']);
        // Per-agent read state and the customer's presence (ADR 2026-11-05-agent-panel-data).
        self::assertArrayHasKey('is_unread', $row);
        self::assertArrayHasKey('requester_online', $row);
    }

    public function testSendsInboxAndTheQueueTheAgentLastOpenedOnTheTicketPage(): void
    {
        $this->boot();
        $ticket = $this->ticket(['assigned_to' => (int) $this->agent->getId()]);
        $this->ticket();
        $show = '/support/agent/tickets/'.$ticket->getReference();

        $page = $this->visit($show);
        self::assertSame('Escalated/Agent/TicketShow', $page['component']);
        self::assertSame(['key' => 'all', 'title' => 'All tickets'], $page['props']['queue']);
        self::assertSame(['mine' => 1, 'unassigned' => 1, 'all' => 2], $page['props']['inbox']['counts']);
        self::assertSame(['counts', 'unread', 'views', 'stats'], array_keys($page['props']['inbox']));

        $this->visit('/support/agent/tickets?assigned_to='.$this->agent->getId());

        $page = $this->visit($show);
        self::assertSame(['key' => 'mine', 'title' => 'My inbox'], $page['props']['queue']);
        self::assertSame(['open' => 1, 'urgent' => 0, 'sla_at_risk' => 0, 'resolved_today' => 0], $page['props']['inbox']['stats']);
    }

    public function testCachesTheInboxPerAgent(): void
    {
        $this->boot(['agent_inbox' => ['cache_seconds' => 30]]);
        static::getContainer()->get('cache.app')->clear();

        $this->ticket(['assigned_to' => (int) $this->agent->getId()]);
        self::assertSame(1, $this->visit('/support/agent/tickets')['props']['inbox']['counts']['mine']);

        $this->ticket(['assigned_to' => (int) $this->agent->getId()]);
        $this->ticket(['assigned_to' => (int) $this->other->getId()]);

        self::assertSame(1, $this->visit('/support/agent/tickets')['props']['inbox']['counts']['mine']);
        $this->client->loginUser($this->other);
        self::assertSame(1, $this->visit('/support/agent/tickets')['props']['inbox']['counts']['mine']);

        static::getContainer()->get('cache.app')->clear();
    }

    public static function pages(): iterable
    {
        yield ['Escalated/Agent/TicketIndex'];
        yield ['Escalated/Agent/TicketShow'];
    }

    #[DataProvider('pages')]
    public function testSendsTheAgentPagesEveryPropTheyDeclare(string $component): void
    {
        $this->boot();
        $ticket = $this->ticket();

        $page = $this->visit(str_ends_with($component, 'Index') ? '/support/agent/tickets' : '/support/agent/tickets/'.$ticket->getReference());

        self::assertSame($component, $page['component']);
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/escalated-pages.json'), true, flags: \JSON_THROW_ON_ERROR);
        $declared = $manifest['props'][$component]['props'];
        self::assertSame([], array_values(array_diff($declared, array_keys($page['props']))));
        self::assertContains('inbox', $declared);
        self::assertContains('queue', $declared);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function ticket(array $attributes = []): Ticket
    {
        ++$this->seq;
        $ticket = (new Ticket())
            ->setSubject('Inbox '.$this->seq)
            ->setDescription('Details')
            ->setRequesterId((int) $this->customer->getId())
            ->setRequesterClass(TestUser::class)
            ->setStatus($attributes['status'] ?? 'open')
            ->setPriority($attributes['priority'] ?? 'low')
            ->setAssignedTo($attributes['assigned_to'] ?? null)
            ->setDepartment($attributes['department'] ?? null)
            ->setResolvedAt($attributes['resolved_at'] ?? null)
            ->setSnoozedUntil($attributes['snoozed_until'] ?? null)
            ->setFirstResponseAt($attributes['first_response_at'] ?? null)
            ->setFirstResponseDueAt($attributes['first_response_due_at'] ?? null)
            ->setResolutionDueAt($attributes['resolution_due_at'] ?? null)
            ->setSlaFirstResponseBreached($attributes['sla_first_response_breached'] ?? false)
            ->setSlaResolutionBreached($attributes['sla_resolution_breached'] ?? false);
        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();
        $ticket->setReference($ticket->generateReference('INB'));
        $em->flush();

        return $ticket;
    }

    private function backdate(Ticket $ticket, string $when): void
    {
        static::entityManager()->getConnection()->update(
            static::entityManager()->getClassMetadata(Ticket::class)->getTableName(),
            ['updated_at' => (new \DateTimeImmutable($when))->format('Y-m-d H:i:s')],
            ['id' => $ticket->getId()],
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function view(int $userId, string $name, array $filters, int $position, bool $shared = false): SavedView
    {
        $view = (new SavedView())->setUserId($userId)->setName($name)->setFilters($filters)->setPosition($position)->setIsShared($shared);
        $em = static::entityManager();
        $em->persist($view);
        $em->flush();

        return $view;
    }

    private function reply(Ticket $ticket, string $body, bool $note, string $when): void
    {
        $em = static::entityManager();
        $reply = (new Reply())
            ->setTicket($em->find(Ticket::class, $ticket->getId()))
            ->setBody($body)
            ->setIsInternalNote($note)
            ->setAuthorId((int) $this->agent->getId())
            ->setAuthorClass(TestUser::class);
        $em->persist($reply);
        $em->flush();
        $em->getConnection()->update(
            $em->getClassMetadata(Reply::class)->getTableName(),
            ['created_at' => (new \DateTimeImmutable($when))->format('Y-m-d H:i:s')],
            ['id' => $reply->getId()],
        );
        $em->clear();
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
}
