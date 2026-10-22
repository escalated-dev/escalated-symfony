<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\Automation;
use Escalated\Symfony\Service\AuditLogService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Admin/AuditLog/Index and Admin/Automations/Form against escalated-laravel's
 * AuditLogController and AutomationController: the props they read, the
 * route names they call, and the saves the form posts.
 */
final class AuditLogAndAutomationPagesTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $admin;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($this->admin);
        $em->flush();
        $this->client->loginUser($this->admin);
    }

    public function testEveryRouteThePagesCallExists(): void
    {
        $routes = static::getContainer()->get(RouterInterface::class)->getRouteCollection();
        foreach (['escalated.admin.audit-log', 'escalated.admin.automations.index', 'escalated.admin.automations.create', 'escalated.admin.automations.store',
            'escalated.admin.automations.edit', 'escalated.admin.automations.update', 'escalated.admin.automations.destroy', 'escalated.admin.automations.delete'] as $name) {
            self::assertNotNull($routes->get($name), $name);
        }
        self::assertStringEndsWith('/admin/audit-log', $routes->get('escalated.admin.audit-log')->getPath());
    }

    public function testAuditLogIndex(): void
    {
        $log = new AuditLogService(static::entityManager());
        $log->log('updated', 'Ticket', 7, 'user', (int) $this->admin->getId(), ['status' => 'open'], ['status' => 'resolved'], '10.0.0.1', 'agent/1');
        $log->log('created', 'Department', 3);

        $page = $this->visit('/support/admin/audit-log');
        self::assertSame('Escalated/Admin/AuditLog/Index', $page['component']);
        $p = $page['props'];
        self::assertSame(['logs', 'filters', 'users', 'actions', 'resourceTypes'], array_keys($p));
        self::assertSame(2, $p['logs']['total']);
        self::assertSame(50, $p['logs']['per_page']);
        self::assertArrayHasKey('links', $p['logs']);

        $rows = array_column($p['logs']['data'], null, 'auditable_type');
        $updated = $rows['Ticket'];
        foreach (['id', 'user_id', 'action', 'auditable_type', 'auditable_id', 'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at', 'user'] as $key) {
            self::assertArrayHasKey($key, $updated, $key);
        }
        self::assertSame('updated', $updated['action']);
        self::assertSame(7, $updated['auditable_id']);
        self::assertSame(['status' => 'open'], $updated['old_values']);
        self::assertSame(['status' => 'resolved'], $updated['new_values']);
        self::assertSame('10.0.0.1', $updated['ip_address']);
        self::assertSame('admin@example.com', $updated['user']['name']);
        self::assertSame($this->admin->getId(), $updated['user']['id']);
        self::assertNull($rows['Department']['user']);

        self::assertSame([['id' => $this->admin->getId(), 'name' => 'admin@example.com']], $p['users']);
        self::assertSame(['created', 'updated', 'deleted'], $p['actions']);
        self::assertSame(['Department', 'Ticket'], $p['resourceTypes']);
    }

    public function testAuditLogFilters(): void
    {
        $log = new AuditLogService(static::entityManager());
        $log->log('updated', 'Ticket', 7, 'user', (int) $this->admin->getId());
        $log->log('created', 'Department', 3);

        $p = $this->visit('/support/admin/audit-log?action=created')['props'];
        self::assertSame(['Department'], array_column($p['logs']['data'], 'auditable_type'));
        self::assertSame(['action' => 'created'], $p['filters']);

        $p = $this->visit('/support/admin/audit-log?user_id='.$this->admin->getId().'&auditable_type=Ticket&date_from=2000-01-01&date_to='.(new \DateTimeImmutable())->format('Y-m-d'))['props'];
        self::assertSame(['Ticket'], array_column($p['logs']['data'], 'auditable_type'));

        $p = $this->visit('/support/admin/audit-log?date_to=2000-01-01')['props'];
        self::assertSame([], $p['logs']['data']);
    }

    public function testAuditLogIsAdminOnly(): void
    {
        $agent = new TestUser('agent@example.com');
        static::entityManager()->persist($agent);
        static::entityManager()->flush();
        $this->client->loginUser($agent);
        $this->client->request('GET', '/support/admin/audit-log', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAutomationCreateAndEditPages(): void
    {
        $page = $this->visit('/support/admin/automations/create');
        self::assertSame('Escalated/Admin/Automations/Form', $page['component']);
        self::assertNull($page['props']['automation'] ?? null);

        $automation = (new Automation())->setName('Stale')->setConditions([['field' => 'hours_since_updated', 'operator' => '>', 'value' => 48]])
            ->setActions([['type' => 'set_ticket_type', 'value' => 'problem']]);
        static::entityManager()->persist($automation);
        static::entityManager()->flush();

        $page = $this->visit('/support/admin/automations/'.$automation->getId().'/edit');
        self::assertSame('Escalated/Admin/Automations/Form', $page['component']);
        $a = $page['props']['automation'];
        self::assertSame($automation->getId(), $a['id']);
        self::assertSame('Stale', $a['name']);
        self::assertSame([['field' => 'hours_since_updated', 'operator' => '>', 'value' => 48]], $a['conditions']);
        self::assertSame([['type' => 'set_ticket_type', 'value' => 'problem']], $a['actions']);
        self::assertTrue($a['active']);

        $this->client->request('GET', '/support/admin/automations/999999/edit', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheFormSavesAndRejectsWhatLaravelRejects(): void
    {
        $this->client->request('POST', '/support/admin/automations', server: ['HTTP_X_INERTIA' => 'true', 'CONTENT_TYPE' => 'application/json', 'HTTP_REFERER' => '/support/admin/automations/create'], content: json_encode([
            'name' => 'Nothing', 'conditions' => [], 'actions' => [['type' => 'add_tag', 'value' => 'x']], 'active' => true,
        ]));
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/support/admin/automations/create', $this->client->getResponse()->headers->get('Location'));
        self::assertSame(0, static::entityManager()->getRepository(Automation::class)->count([]));

        $this->client->request('POST', '/support/admin/automations', server: ['HTTP_X_INERTIA' => 'true', 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'name' => 'Close stale', 'conditions' => [['field' => 'hours_since_updated', 'operator' => '>', 'value' => 48]],
            'actions' => [['type' => 'change_status', 'value' => 'closed']], 'active' => true,
        ]));
        self::assertResponseRedirects();
        $saved = static::entityManager()->getRepository(Automation::class)->findOneBy(['name' => 'Close stale']);
        self::assertNotNull($saved);

        $this->client->request('PUT', '/support/admin/automations/'.$saved->getId(), server: ['HTTP_X_INERTIA' => 'true', 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'name' => 'Close stale', 'conditions' => $saved->getConditions(), 'actions' => $saved->getActions(), 'active' => false,
        ]));
        self::assertResponseRedirects();
        static::entityManager()->clear();
        self::assertFalse(static::entityManager()->getRepository(Automation::class)->find($saved->getId())->isActive());

        $this->client->request('PUT', '/support/admin/automations/'.$saved->getId(), server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'name' => '', 'conditions' => [['field' => 'status', 'value' => 'open']], 'actions' => [],
        ]));
        self::assertResponseStatusCodeSame(422);
        $errors = json_decode((string) $this->client->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('name', $errors);
        self::assertArrayHasKey('actions', $errors);

        $this->client->request('DELETE', '/support/admin/automations/'.$saved->getId(), server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseRedirects();
        self::assertSame(0, static::entityManager()->getRepository(Automation::class)->count([]));
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
