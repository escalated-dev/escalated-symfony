<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Plugins;

use Escalated\Symfony\Entity\Contact;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\PluginStoreRecord;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\EventListener\PluginEventRelay;
use Escalated\Symfony\Plugin\PluginContext;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\AuditPlugin;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\CrmPlugin;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\PluginRecorder;

/**
 * Plugin contract checklist, "Hooks and context": canonical events to active
 * plugins, filters, ctx.config and ctx.store, the domain ctx methods, and the
 * `escalated.plugins` share.
 */
final class PluginHooksAndContextTest extends PluginTestCase
{
    private const TICKET_KEYS = ['id', 'reference', 'subject', 'title', 'status', 'priority', 'department_id', 'assigned_to', 'requester_id', 'metadata', 'created_at', 'updated_at'];

    protected function setUp(): void
    {
        $this->boot();
    }

    public function testCreatingATicketOverHttpDeliversTicketCreatedToActivePluginsOnly(): void
    {
        $this->activate(CrmPlugin::SLUG);
        $this->loginCustomer();

        $this->inertia('POST', '/support/customer/tickets', ['subject' => 'Printer on fire', 'description' => 'Smoke everywhere.', 'priority' => 'high']);
        self::assertResponseRedirects();

        $events = PluginRecorder::events(CrmPlugin::NAME, EventNames::TICKET_CREATED);
        self::assertCount(1, $events);
        $event = $events[0];
        foreach (self::TICKET_KEYS as $key) {
            self::assertArrayHasKey($key, $event, 'flat ticket: '.$key);
            self::assertArrayHasKey($key, $event['ticket'], 'ticket: '.$key);
        }
        self::assertSame('Printer on fire', $event['subject']);
        self::assertSame('Printer on fire', $event['title']);
        self::assertSame('high', $event['priority']);
        self::assertMatchesRegularExpression('/^ESC-/', $event['reference'], 'delivered after the reference is written');
        self::assertSame($event['ticket']['id'], $event['id']);
        self::assertSame([], PluginRecorder::hooks(AuditPlugin::NAME), 'an inactive plugin hears nothing');
    }

    public function testAReplyDeliversReplyCreatedWithTheReplyAndTicketAndNoLegacyName(): void
    {
        $this->activate(CrmPlugin::SLUG);
        $customer = $this->loginCustomer();
        $ticket = $this->tickets()->create(['subject' => 'Help', 'description' => 'Please', 'requester_id' => $customer->getId(), 'requester_class' => $customer::class]);
        $this->relay()->flush();
        PluginRecorder::$calls = [];

        $this->inertia('POST', '/support/customer/tickets/'.$ticket->getReference().'/reply', ['body' => 'Any news?']);
        self::assertResponseRedirects();

        $events = PluginRecorder::events(CrmPlugin::NAME, EventNames::REPLY_CREATED);
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame('Any news?', $event['body']);
        self::assertSame($event['id'], $event['reply']['id']);
        self::assertFalse($event['reply']['is_internal']);
        self::assertSame($ticket->getId(), $event['ticket_id']);
        self::assertSame($ticket->getId(), $event['ticket']['id']);
        self::assertSame($ticket->getReference(), $event['ticket']['reference']);

        $this->tickets()->addReply($ticket, $customer->getId(), 'Internal', true);
        $this->tickets()->changeStatus($ticket, Ticket::STATUS_RESOLVED);
        $this->relay()->flush();

        $hooks = PluginRecorder::hooks(CrmPlugin::NAME);
        self::assertSame([EventNames::REPLY_CREATED, EventNames::NOTE_CREATED, EventNames::STATUS_CHANGED, EventNames::TICKET_RESOLVED], $hooks);
        foreach ($hooks as $hook) {
            self::assertContains($hook, EventNames::CANONICAL);
            self::assertArrayNotHasKey($hook, EventNames::LEGACY);
        }
        $changed = PluginRecorder::events(CrmPlugin::NAME, EventNames::STATUS_CHANGED)[0];
        self::assertSame('open', $changed['old_status']);
        self::assertSame('resolved', $changed['new_status']);
    }

    public function testAPluginActionThatThrowsDoesNotFailTheRequest(): void
    {
        $this->activate(CrmPlugin::SLUG);
        $this->activate(AuditPlugin::SLUG);
        PluginRecorder::$throwOn = [CrmPlugin::NAME.':'.EventNames::TICKET_CREATED];
        $this->loginCustomer();

        $this->inertia('POST', '/support/customer/tickets', ['subject' => 'Still works', 'description' => 'Yes.']);

        self::assertResponseRedirects();
        self::assertNotNull(static::entityManager()->getRepository(Ticket::class)->findOneBy(['subject' => 'Still works']));
        self::assertCount(1, PluginRecorder::events(AuditPlugin::NAME, EventNames::TICKET_CREATED), 'the next plugin still hears it');
    }

    public function testTagAssignmentPriorityAndDepartmentEventsCarryTheirExtras(): void
    {
        $this->activate(CrmPlugin::SLUG);
        $department = (new Department())->setName('Billing')->setSlug('billing');
        static::entityManager()->persist($department);
        static::entityManager()->flush();
        $ticket = $this->tickets()->create(['subject' => 'Extras']);
        $tag = $this->registry()->context($this->crm())->call('tags.create', ['data' => ['name' => 'VIP']]);

        $this->tickets()->addTags($ticket, [$tag['id']]);
        $this->tickets()->changePriority($ticket, 'urgent');
        $this->tickets()->changeDepartment($ticket, $department->getId());
        $this->relay()->flush();

        self::assertSame(['id' => $tag['id'], 'name' => 'VIP', 'slug' => 'vip'], PluginRecorder::events(CrmPlugin::NAME, EventNames::TAG_ADDED)[0]['tag']);
        $priority = PluginRecorder::events(CrmPlugin::NAME, EventNames::PRIORITY_CHANGED)[0];
        self::assertSame(['medium', 'urgent'], [$priority['old_priority'], $priority['new_priority']]);
        $moved = PluginRecorder::events(CrmPlugin::NAME, EventNames::DEPARTMENT_CHANGED)[0];
        self::assertSame([null, $department->getId()], [$moved['old_department_id'], $moved['new_department_id']]);
        self::assertSame($department->getId(), $moved['ticket']['department_id']);
    }

    public function testFiltersRunInPriorityOrderOnlyForActivePluginsAndSkipAThrowingOne(): void
    {
        self::assertSame('x', $this->registry()->applyFilter('demo.value', 'x'), 'no active plugin');

        $this->activate(CrmPlugin::SLUG);
        self::assertSame('x|crm', $this->registry()->applyFilter('demo.value', 'x'));

        $this->activate(AuditPlugin::SLUG);
        self::assertSame('x|audit|crm', $this->registry()->applyFilter('demo.value', 'x'), 'audit (5) before crm (20)');
        self::assertSame('x', $this->registry()->applyFilter('other.hook', 'x'));

        PluginRecorder::$throwOn = [AuditPlugin::NAME.':demo.value'];
        self::assertSame('x|crm', $this->registry()->applyFilter('demo.value', 'x'));
    }

    public function testCtxConfigSetMerges(): void
    {
        $ctx = $this->ctx(CrmPlugin::SLUG);

        $ctx->configSet(['api_key' => 'k1', 'region' => 'eu']);
        $ctx->configSet(['api_key' => 'k2']);

        self::assertSame(['api_key' => 'k2', 'region' => 'eu'], $ctx->configAll());
        self::assertSame('eu', $ctx->configGet('region'));
        self::assertNull($ctx->configGet('missing'));
    }

    public function testCtxStoreQueryHandlesTheOperators(): void
    {
        $ctx = $this->ctx(CrmPlugin::SLUG);
        $ctx->storeInsert('deals', ['key' => 'a', 'amount' => 10, 'stage' => 'won', 'owner' => ['team' => 'east'], 'tags' => ['big']]);
        $ctx->storeInsert('deals', ['key' => 'b', 'amount' => 9, 'stage' => 'lost', 'owner' => ['team' => 'west']]);
        $ctx->storeInsert('deals', ['key' => 'c', 'amount' => 25.5, 'stage' => 'open']);
        $ctx->storeInsert('deals', ['key' => 'd', 'stage' => 'open']);

        $keys = static fn (array $rows): array => array_column($rows, 'key');

        self::assertSame(['a'], $keys($ctx->storeQuery('deals', ['stage' => 'won'])));
        self::assertSame(['a'], $keys($ctx->storeQuery('deals', ['tags' => 'big'])), 'an array field matches a value it holds');
        self::assertSame(['a'], $keys($ctx->storeQuery('deals', ['owner.team' => 'east'])));
        self::assertSame(['a', 'c'], $keys($ctx->storeQuery('deals', ['amount' => ['$gt' => 9]])), '10 > 9 as numbers');
        self::assertSame(['a', 'b', 'c'], $keys($ctx->storeQuery('deals', ['amount' => ['$gte' => 9]])));
        self::assertSame(['b'], $keys($ctx->storeQuery('deals', ['amount' => ['$lt' => 10]])));
        self::assertSame(['a', 'b'], $keys($ctx->storeQuery('deals', ['amount' => ['$lte' => 10]])));
        self::assertSame(['b', 'c', 'd'], $keys($ctx->storeQuery('deals', ['stage' => ['$ne' => 'won']])));
        self::assertSame(['a', 'b'], $keys($ctx->storeQuery('deals', ['stage' => ['$in' => ['won', 'lost']]])));
        self::assertSame(['c', 'd'], $keys($ctx->storeQuery('deals', ['stage' => ['$nin' => ['won', 'lost']]])));
        self::assertSame(['a', 'c'], $keys($ctx->storeQuery('deals', ['amount' => ['$gt' => 5, '$ne' => 9]])));
        self::assertSame(['b', 'a', 'c', 'd'], $keys($ctx->storeQuery('deals', [], ['orderBy' => 'amount'])));
        self::assertSame(['c', 'a'], $keys($ctx->storeQuery('deals', [], ['orderBy' => 'amount', 'order' => 'desc', 'limit' => 2])));

        $row = $ctx->storeQuery('deals', ['stage' => 'won'])[0];
        self::assertIsInt($row['_id']);

        $ctx->storeUpdate('deals', 'a', ['stage' => 'closed']);
        self::assertSame(['closed', 10], [$ctx->storeQuery('deals', ['key' => 'a'])[0]['stage'], $ctx->storeQuery('deals', ['key' => 'a'])[0]['amount']], 'update merges');
        $ctx->storeDelete('deals', 'a');
        self::assertSame([], $ctx->storeQuery('deals', ['key' => 'a']));

        $ctx->storeSet('flags', 'sync', ['on' => true]);
        $ctx->storeSet('flags', 'sync', ['on' => false]);
        self::assertSame(['on' => false], $ctx->storeGet('flags', 'sync'));
        self::assertSame(1, static::entityManager()->getRepository(PluginStoreRecord::class)->count(['plugin' => CrmPlugin::NAME, 'collection' => 'flags']));
    }

    public function testCtxStoreQueryRejectsAFieldNameWithSqlInIt(): void
    {
        $ctx = $this->ctx(CrmPlugin::SLUG);

        foreach (["stage'); DROP TABLE escalated_tickets; --", 'a..b', '1abc', 'data->>x'] as $field) {
            try {
                $ctx->storeQuery('deals', [$field => 'x']);
                self::fail('accepted '.$field);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Invalid store field name', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $ctx->storeQuery('deals', [], ['orderBy' => 'amount desc; --']);
    }

    public function testOnePluginCannotReadAnothersRows(): void
    {
        $crm = $this->ctx(CrmPlugin::SLUG);
        $audit = $this->ctx(AuditPlugin::SLUG);
        $crm->storeSet('contacts', 'c1', ['name' => 'Ada']);
        $crm->configSet(['api_key' => 'secret']);

        self::assertNull($audit->storeGet('contacts', 'c1'));
        self::assertSame([], $audit->storeQuery('contacts'));
        self::assertSame([], $audit->configAll());
        // A `plugin` parameter naming another plugin is ignored.
        self::assertSame([], $audit->call('store.query', ['plugin' => CrmPlugin::NAME, 'collection' => 'contacts']));
        self::assertSame(['name' => 'Ada'], $crm->storeGet('contacts', 'c1'));
    }

    public function testCtxEmitReachesActivePluginsButCannotImpersonateTheHost(): void
    {
        $this->loginAdmin();
        $this->activate(CrmPlugin::SLUG);
        $this->activate(AuditPlugin::SLUG);

        $this->client->request('POST', '/support/api/plugins/'.CrmPlugin::SLUG.'/emit', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        self::assertSame([['contacts' => 3]], PluginRecorder::events(AuditPlugin::NAME, 'crm.synced'));
        self::assertSame([], PluginRecorder::events(AuditPlugin::NAME, 'ticket.created'));
        self::assertSame([], PluginRecorder::events(AuditPlugin::NAME, 'plugin.activated'));
    }

    public function testCtxTicketsAndRepliesGoThroughTheTicketServiceAndTakeOnlyTheContractsFields(): void
    {
        $this->activate(CrmPlugin::SLUG);
        $ctx = $this->ctx(CrmPlugin::SLUG);

        $ticket = $ctx->call('tickets.create', ['data' => [
            'title' => 'From the CRM',
            'body' => 'A contact wrote in.',
            'priority' => 'high',
            'requester_email' => 'Ada@Example.com',
            'requester_name' => 'Ada',
            'metadata' => ['crm_id' => 'c1'],
            'requester_id' => 999,
            'assigned_to' => 5,
            'guest_token' => 'forged',
        ]]);
        self::assertSame('From the CRM', $ticket['subject']);
        self::assertSame('A contact wrote in.', $ticket['description']);
        self::assertSame('api', $ticket['channel']);
        self::assertSame('high', $ticket['priority']);
        self::assertSame(['crm_id' => 'c1'], $ticket['metadata']);
        self::assertNull($ticket['requester_id'], 'a plugin never names the requester');
        self::assertNull($ticket['assigned_to']);
        $contact = static::entityManager()->find(Contact::class, $ticket['contact_id']);
        self::assertSame('ada@example.com', $contact?->getEmail());
        $stored = static::entityManager()->find(Ticket::class, $ticket['id']);
        self::assertNotSame('forged', $stored?->getGuestToken());

        $this->relay()->flush();
        self::assertCount(1, PluginRecorder::events(CrmPlugin::NAME, EventNames::TICKET_CREATED), 'events fire for plugin writes too');

        $updated = $ctx->call('tickets.update', ['id' => $ticket['id'], 'data' => ['priority' => 'low', 'status' => 'in_progress', 'channel' => 'email']]);
        self::assertSame('low', $updated['priority']);
        self::assertSame('in_progress', $updated['status']);
        self::assertSame('api', $updated['channel'], 'update does not change the channel');

        self::assertSame([$ticket['id']], array_column($ctx->call('tickets.query', ['filter' => ['priority' => 'low', 'channel' => 'api']]), 'id'));
        self::assertSame($ticket['id'], $ctx->call('tickets.find', ['id' => $ticket['id']])['id']);
        try {
            $ctx->call('tickets.query', ['filter' => ['guest_token' => 'x']]);
            self::fail('filtered on a column outside the contract');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('cannot filter on "guest_token"', $e->getMessage());
        }

        $note = $ctx->call('replies.create', ['data' => ['ticket_id' => $ticket['id'], 'body' => 'Synced to CRM', 'is_internal' => true, 'author_id' => 42]]);
        self::assertTrue($note['is_internal']);
        self::assertSame('note', $note['type']);
        self::assertNull($note['user_id'], 'outside a request the plugin acts as no user');
        self::assertSame([$note['id']], array_column($ctx->call('replies.query', ['filter' => ['ticket_id' => $ticket['id'], 'is_internal' => true]]), 'id'));
        $this->expectException(\InvalidArgumentException::class);
        $ctx->call('replies.query', ['filter' => ['body' => 'x']]);
    }

    public function testContactsTagsDepartmentsAndAgents(): void
    {
        $ctx = $this->ctx(CrmPlugin::SLUG);
        $agent = $this->user('agent@example.com', [], agent: true);

        $contact = $ctx->call('contacts.create', ['data' => ['email' => 'grace@example.com', 'name' => 'Grace']]);
        self::assertSame($contact['id'], $ctx->call('contacts.create', ['data' => ['email' => 'GRACE@example.com']])['id'], 'found, not duplicated');
        self::assertSame('Grace', $ctx->call('contacts.findByEmail', ['email' => 'grace@example.com'])['name']);
        self::assertSame('grace@example.com', $ctx->call('contacts.find', ['id' => $contact['id']])['email']);

        $tag = $ctx->call('tags.create', ['data' => ['name' => 'Escalation']]);
        self::assertSame(['escalation'], array_column($ctx->call('tags.all'), 'slug'));
        self::assertSame($tag['id'], $ctx->call('tags.create', ['data' => ['name' => 'escalation']])['id']);

        self::assertSame([], $ctx->call('departments.all'));
        self::assertNull($ctx->call('departments.find', ['id' => 1]));

        self::assertSame([$agent->getId()], array_column($ctx->call('agents.all'), 'id'));
        self::assertSame('agent@example.com', $ctx->call('agents.find', ['id' => $agent->getId()])['email']);
        self::assertNull($ctx->call('agents.find', ['id' => 999999]));
        self::assertNull($ctx->currentUser());
    }

    public function testEscalatedPluginsSharesMenusSlotsAndWidgetsOfActivePluginsOnly(): void
    {
        $this->loginAdmin();

        $this->visit('/support/admin/plugins');
        self::assertEquals(new \stdClass(), $this->shared()['plugins'], 'no active plugin: {}');

        $this->activate(CrmPlugin::SLUG);
        $this->visit('/support/admin/plugins');
        $plugins = $this->shared()['plugins'];

        self::assertSame([
            ['label' => 'CRM', 'route' => '/support/admin/plugins/'.CrmPlugin::SLUG.'/settings', 'icon' => 'link', 'position' => 5, 'section' => 'admin', 'plugin' => CrmPlugin::SLUG],
            ['label' => 'CRM lookups', 'route' => '/support/admin/plugins/'.CrmPlugin::SLUG.'/agent-view', 'icon' => null, 'position' => 7, 'section' => 'agent', 'plugin' => CrmPlugin::SLUG],
        ], $plugins['menuItems'], 'the Secret page needs a capability the admin lacks');
        self::assertSame(['ticket.show.sidebar' => [
            ['plugin' => CrmPlugin::SLUG, 'component' => 'CrmPanel', 'data' => ['compact' => true], 'position' => 3],
        ]], $plugins['pageComponents']);
        self::assertSame([
            ['id' => CrmPlugin::SLUG.'.CrmStats', 'title' => 'CRM contacts', 'component' => 'CrmStats', 'plugin' => CrmPlugin::SLUG, 'position' => 2, 'width' => 'half'],
        ], $plugins['dashboardWidgets']);

        $this->loginAgent();
        $this->visit('/support/agent/tickets');
        $menu = array_column($this->shared()['plugins']['menuItems'], 'label');
        self::assertSame(['CRM lookups'], $menu, 'admin menu items go to admins only');

        $this->client->loginUser($this->user('keyholder@example.com', ['ROLE_ESCALATED_ADMIN', CrmPlugin::SECRET_ROLE], agent: true));
        $this->visit('/support/admin/plugins');
        self::assertContains('Secret', array_column($this->shared()['plugins']['menuItems'], 'label'));
        self::assertCount(2, $this->shared()['plugins']['pageComponents']['ticket.show.sidebar']);
    }

    private function tickets(): TicketService
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);

        return $service;
    }

    private function relay(): PluginEventRelay
    {
        $relay = static::getContainer()->get(PluginEventRelay::class);
        \assert($relay instanceof PluginEventRelay);

        return $relay;
    }

    private function crm(): \Escalated\Symfony\Plugin\RegisteredPlugin
    {
        $plugin = $this->registry()->find(CrmPlugin::SLUG);
        self::assertNotNull($plugin);

        return $plugin;
    }

    private function ctx(string $slug): PluginContext
    {
        $plugin = $this->registry()->find($slug);
        self::assertNotNull($plugin);

        return $this->registry()->context($plugin);
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(): array
    {
        $shared = $this->client->getRequest()->attributes->get('_escalated_shared');
        self::assertIsArray($shared);

        return $shared;
    }
}
