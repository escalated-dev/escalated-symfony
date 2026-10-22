<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\CannedResponse;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\DepartmentAgent;
use Escalated\Symfony\Entity\EscalationRule;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * The admin configuration pages against what the shared pages read and post
 * (escalated-laravel's Department, Tag, SlaPolicy, EscalationRule, Macro,
 * CannedResponse and Webhook admin controllers): Departments/Index rendered
 * the entities themselves, Tags/Index too, there was no SLA policy admin or
 * Departments/EscalationRules Form page, and several route names the pages
 * call through Ziggy did not exist.
 */
final class AdminConfigPagesTest extends EscalatedWebTestCase
{
    /**
     * Every route name these pages call, from @escalated-dev/escalated 0.12.3.
     */
    private const PAGE_ROUTES = [
        'escalated.admin.departments.index', 'escalated.admin.departments.create', 'escalated.admin.departments.store',
        'escalated.admin.departments.edit', 'escalated.admin.departments.update', 'escalated.admin.departments.destroy',
        'escalated.admin.tags.store', 'escalated.admin.tags.update', 'escalated.admin.tags.destroy',
        'escalated.admin.sla-policies.index', 'escalated.admin.sla-policies.create', 'escalated.admin.sla-policies.store',
        'escalated.admin.sla-policies.edit', 'escalated.admin.sla-policies.update', 'escalated.admin.sla-policies.destroy',
        'escalated.admin.escalation-rules.index', 'escalated.admin.escalation-rules.create', 'escalated.admin.escalation-rules.store',
        'escalated.admin.escalation-rules.edit', 'escalated.admin.escalation-rules.update', 'escalated.admin.escalation-rules.destroy',
        'escalated.admin.macros.store', 'escalated.admin.macros.update', 'escalated.admin.macros.destroy',
        'escalated.admin.canned-responses.store', 'escalated.admin.canned-responses.destroy',
        'escalated.admin.webhooks.index', 'escalated.admin.webhooks.create', 'escalated.admin.webhooks.store',
        'escalated.admin.webhooks.edit', 'escalated.admin.webhooks.update', 'escalated.admin.webhooks.destroy',
        'escalated.admin.webhooks.deliveries', 'escalated.admin.webhooks.retry',
    ];

    /** Names hosts may already link to, kept working. */
    private const KEPT_ROUTES = [
        'escalated.admin.departments.delete', 'escalated.admin.tags.delete', 'escalated.admin.macros.delete',
        'escalated.admin.webhooks.delete', 'escalated.admin.escalation_rules.index', 'escalated.admin.escalation_rules.store',
        'escalated.admin.escalation_rules.update', 'escalated.admin.escalation_rules.delete', 'escalated.admin.escalation_rules.run',
        'escalated.admin.canned_responses.index', 'escalated.admin.canned_responses.store', 'escalated.admin.canned_responses.destroy',
    ];

    private KernelBrowser $client;
    private TestUser $admin;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($this->admin);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $this->admin->getId()));
        $em->flush();
        $this->client->loginUser($this->admin);
    }

    public function testEveryRouteThePagesCallExists(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);
        $missing = array_values(array_filter([...self::PAGE_ROUTES, ...self::KEPT_ROUTES], static fn (string $name): bool => null === $router->getRouteCollection()->get($name)));

        self::assertSame([], $missing);
    }

    public function testDepartmentsIndexAndForm(): void
    {
        $em = static::entityManager();
        $billing = (new Department())->setName('Billing')->setSlug('billing');
        $em->persist($billing);
        $em->flush();
        $em->persist(new DepartmentAgent($billing, (int) $this->admin->getId()));
        $em->flush();
        $this->tickets()->create(['subject' => 'One', 'department_id' => $billing->getId()]);

        $page = $this->visit('/support/admin/departments');
        self::assertSame('Escalated/Admin/Departments/Index', $page['component']);
        $row = $page['props']['departments'][0];
        foreach (['id', 'name', 'slug', 'description', 'is_active', 'tickets_count', 'agents_count'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame(1, $row['tickets_count']);
        self::assertSame(1, $row['agents_count']);

        $page = $this->visit('/support/admin/departments/create');
        self::assertSame(['component' => 'Escalated/Admin/Departments/Form', 'props' => ['department' => null, 'schedules' => []]], $page);

        $this->send('POST', '/support/admin/departments', ['name' => 'Sales Team', 'slug' => '', 'description' => 'Pre-sales', 'is_active' => true]);
        self::assertResponseRedirects('/support/admin/departments');
        $sales = $em->getRepository(Department::class)->findOneBy(['name' => 'Sales Team']);
        self::assertSame('sales-team', $sales?->getSlug());
        self::assertSame('Pre-sales', $sales->getDescription());

        $page = $this->visit('/support/admin/departments/'.$sales->getId().'/edit');
        self::assertSame('Sales Team', $page['props']['department']['name']);
        self::assertSame('sales-team', $page['props']['department']['slug']);

        $this->send('PUT', '/support/admin/departments/'.$sales->getId(), ['name' => 'Sales', 'slug' => 'sales', 'description' => '', 'is_active' => false]);
        $em->clear();
        $sales = $em->getRepository(Department::class)->find($sales->getId());
        self::assertSame('Sales', $sales->getName());
        self::assertFalse($sales->isActive());
        self::assertNull($sales->getDescription());

        $this->send('POST', '/support/admin/departments', ['name' => '', 'slug' => 'billing']);
        self::assertSame(['name', 'slug'], array_keys($this->lastErrors()));

        $this->send('DELETE', '/support/admin/departments/'.$sales->getId(), []);
        self::assertResponseRedirects('/support/admin/departments');
        self::assertNull($em->getRepository(Department::class)->find($sales->getId()));
    }

    public function testTagsAreRowsWithTicketCountsAndTakeJsonWrites(): void
    {
        $em = static::entityManager();
        $vip = (new Tag())->setName('vip')->setSlug('vip')->setColor('#f00');
        $em->persist($vip);
        $em->flush();
        $ticket = $this->tickets()->create(['subject' => 'Tagged']);
        $this->tickets()->addTags($ticket, [(int) $vip->getId()]);

        $page = $this->visit('/support/admin/tags');
        self::assertSame('Escalated/Admin/Tags/Index', $page['component']);
        self::assertSame([['id' => $vip->getId(), 'name' => 'vip', 'slug' => 'vip', 'color' => '#f00', 'tickets_count' => 1]], $page['props']['tags']);

        $this->send('POST', '/support/admin/tags', ['name' => 'Needs Review', 'color' => '#00f']);
        $tag = $em->getRepository(Tag::class)->findOneBy(['name' => 'Needs Review']);
        self::assertSame('needs-review', $tag?->getSlug());
        $this->send('PUT', '/support/admin/tags/'.$tag->getId(), ['name' => 'Review', 'color' => '#0f0']);
        $em->clear();
        self::assertSame('#0f0', $em->getRepository(Tag::class)->find($tag->getId())->getColor());

        $this->send('POST', '/support/admin/tags', ['name' => 'VIP']);
        self::assertArrayHasKey('name', $this->lastErrors());
        $this->send('DELETE', '/support/admin/tags/'.$tag->getId(), []);
        self::assertNull($em->getRepository(Tag::class)->find($tag->getId()));
    }

    public function testSlaPolicyAdmin(): void
    {
        $page = $this->visit('/support/admin/sla-policies/create');
        self::assertSame('Escalated/Admin/SlaPolicies/Form', $page['component']);
        self::assertNull($page['props']['policy']);
        self::assertSame(['low', 'medium', 'high', 'urgent', 'critical'], $page['props']['priorities']);

        $this->send('POST', '/support/admin/sla-policies', [
            'name' => 'Standard',
            'description' => 'Most tickets',
            'is_default' => true,
            'first_response_hours' => ['low' => 24, 'urgent' => 1, 'high' => ''],
            'resolution_hours' => ['low' => 72, 'urgent' => 4.5],
            'business_hours_only' => true,
            'is_active' => true,
        ]);
        self::assertResponseRedirects('/support/admin/sla-policies');
        $em = static::entityManager();
        $policy = $em->getRepository(SlaPolicy::class)->findOneBy(['name' => 'Standard']);
        self::assertNotNull($policy);
        self::assertEquals(['low' => 24, 'urgent' => 1], $policy->getFirstResponseHours());
        self::assertSame(4.5, $policy->getResolutionHoursFor('urgent'));
        self::assertTrue($policy->isDefault());
        self::assertTrue($policy->isBusinessHoursOnly());

        $ticket = $this->tickets()->create(['subject' => 'Under SLA']);
        $ticket->setSlaPolicy($policy);
        $em->flush();

        $page = $this->visit('/support/admin/sla-policies');
        self::assertSame('Escalated/Admin/SlaPolicies/Index', $page['component']);
        $row = $page['props']['policies'][0];
        foreach (['id', 'name', 'is_active', 'is_default', 'business_hours_only', 'tickets_count'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame(1, $row['tickets_count']);

        $page = $this->visit('/support/admin/sla-policies/'.$policy->getId().'/edit');
        self::assertEquals(['low' => 72, 'urgent' => 4.5], $page['props']['policy']['resolution_hours']);
        foreach (['name', 'description', 'is_default', 'first_response_hours', 'resolution_hours', 'business_hours_only', 'is_active'] as $key) {
            self::assertArrayHasKey($key, $page['props']['policy']);
        }

        $this->send('PUT', '/support/admin/sla-policies/'.$policy->getId(), ['name' => '', 'first_response_hours' => 'x']);
        self::assertSame(['name', 'first_response_hours', 'resolution_hours'], array_keys($this->lastErrors()));

        $this->send('DELETE', '/support/admin/sla-policies/'.$policy->getId(), []);
        self::assertResponseRedirects('/support/admin/sla-policies');
        $em->clear();
        self::assertNull($em->getRepository(SlaPolicy::class)->find($policy->getId()));
        self::assertNull($em->getRepository(Ticket::class)->find($ticket->getId())->getSlaPolicy());
    }

    public function testEscalationRuleFormPages(): void
    {
        $page = $this->visit('/support/admin/escalation-rules/create');
        self::assertSame(['component' => 'Escalated/Admin/EscalationRules/Form', 'props' => ['rule' => null]], $page);

        $this->send('POST', '/support/admin/escalation-rules', [
            'name' => 'Stale urgent', 'description' => '', 'trigger_type' => 'time_based',
            'conditions' => [['field' => 'priority', 'value' => 'urgent']],
            'actions' => [['type' => 'change_priority', 'value' => 'critical']],
            'order' => 2, 'is_active' => true,
        ]);
        self::assertResponseRedirects('/support/admin/escalation-rules');
        $rule = static::entityManager()->getRepository(EscalationRule::class)->findOneBy(['name' => 'Stale urgent']);
        self::assertNotNull($rule);

        $page = $this->visit('/support/admin/escalation-rules/'.$rule->getId().'/edit');
        self::assertSame('Escalated/Admin/EscalationRules/Form', $page['component']);
        foreach (['id', 'name', 'description', 'trigger_type', 'conditions', 'actions', 'order', 'is_active'] as $key) {
            self::assertArrayHasKey($key, $page['props']['rule']);
        }
        self::assertSame(2, $page['props']['rule']['order']);
    }

    public function testMacrosAndCannedResponsesNameTheirCreator(): void
    {
        $em = static::entityManager();
        $em->persist((new Macro())->setName('Close')->setActions([])->setCreatedBy((int) $this->admin->getId()));
        $em->persist((new CannedResponse())->setTitle('Hi')->setBody('Hello')->setCreatedBy((int) $this->admin->getId()));
        $em->flush();

        self::assertSame(['id' => $this->admin->getId(), 'name' => 'admin@example.com'], $this->visit('/support/admin/macros')['props']['macros'][0]['creator']);
        self::assertSame(['id' => $this->admin->getId(), 'name' => 'admin@example.com'], $this->visit('/support/admin/canned-responses')['props']['responses'][0]['creator']);
    }

    private function tickets(): TicketService
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);

        return $service;
    }

    /**
     * @return array<string, string>
     */
    private function lastErrors(): array
    {
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');

        return end($errors) ?: [];
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
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $uri, array $body): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
        ], content: (string) json_encode($body));
    }
}
