<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Permission;
use Escalated\Symfony\Entity\Role;
use Escalated\Symfony\Entity\RoleUser;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Security\PermissionSeeder;
use Escalated\Symfony\Security\RoutePermissions;
use Escalated\Symfony\Service\ApiTokenService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Roles & permissions behind the install-level switch
 * (ADR 2026-10-29-configurable-access-features, "Port test checklist":
 * Switches and Roles & permissions), through the real HTTP stack.
 */
final class RolesPermissionsTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $admin;
    private TestUser $agent;
    private TestUser $customer;

    // ---- Switches ----------------------------------------------------------

    public function testWithTheSwitchOffTheRolesScreensAnswer404AndFeaturesAreOff(): void
    {
        $this->boot(false);
        $this->give($this->admin, 'admin');
        $this->client->loginUser($this->admin);

        foreach ([['GET', '/support/admin/roles'], ['GET', '/support/admin/roles/create'], ['POST', '/support/admin/roles'], ['GET', '/support/admin/roles/1/edit'], ['PUT', '/support/admin/roles/1'], ['DELETE', '/support/admin/roles/1'], ['PUT', '/support/admin/users/'.$this->agent->getId().'/roles']] as [$method, $uri]) {
            $this->send($method, $uri, ['name' => 'X', 'role_ids' => []]);
            self::assertResponseStatusCodeSame(404, $method.' '.$uri);
        }

        $this->visit('/support/admin/tags');
        $shared = $this->shared();
        self::assertSame(['newsletters' => false, 'roles_permissions' => false, 'two_factor' => false, 'sso' => false], $shared['features']);
        self::assertSame(['*'], $shared['permissions'], 'staff get * while the gates decide everything');
    }

    public function testWithTheSwitchOffNonStaffGetNoPermissions(): void
    {
        $this->boot(false);
        $this->client->loginUser($this->customer);

        $this->visit('/support/customer/tickets');
        self::assertSame([], $this->shared()['permissions']);
    }

    public function testWithTheSwitchOnTheRolesScreensWorkAndFeaturesSayOn(): void
    {
        $this->boot(true);
        $this->give($this->admin, 'admin');
        $this->give($this->agent, 'agent');
        $this->client->loginUser($this->admin);

        $page = $this->visit('/support/admin/roles');
        self::assertSame('Escalated/Admin/Roles/Index', $page['component']);
        $roles = array_column($page['props']['roles'], null, 'slug');
        self::assertSame(['admin', 'agent', 'light_agent'], array_keys($roles));
        self::assertTrue($roles['admin']['is_system']);
        self::assertSame(1, $roles['agent']['users_count']);
        foreach (['id', 'name', 'slug', 'description', 'is_system', 'users_count'] as $key) {
            self::assertArrayHasKey($key, $roles['light_agent']);
        }

        $shared = $this->shared();
        self::assertTrue($shared['features']['roles_permissions']);
        self::assertFalse($shared['features']['two_factor']);
        self::assertFalse($shared['features']['sso']);
        self::assertContains('role.manage', $shared['permissions']);
        self::assertNotContains('*', $shared['permissions']);

        $form = $this->visit('/support/admin/roles/create');
        self::assertSame('Escalated/Admin/Roles/Form', $form['component']);
        self::assertSame(['ticket.view', 'ticket.create'], \array_slice(array_column($form['props']['permissions']['Tickets'], 'slug'), 0, 2));
        $tagView = $this->permissionId('tag.view');

        $this->send('POST', '/support/admin/roles', ['name' => 'Tag Readers', 'description' => 'Read tags', 'permissions' => [$tagView]]);
        self::assertResponseRedirects('/support/admin/roles');
        $role = $this->role('tag_readers');
        self::assertSame(['tag.view'], $this->slugs($role));

        $edit = $this->visit('/support/admin/roles/'.$role->getId().'/edit');
        self::assertSame('Escalated/Admin/Roles/Form', $edit['component']);
        self::assertSame('Tag Readers', $edit['props']['role']['name']);
        self::assertSame([$tagView], array_column($edit['props']['role']['permissions'], 'id'));

        $this->send('PUT', '/support/admin/roles/'.$role->getId(), ['name' => 'Tag Keepers', 'permissions' => [$tagView, $this->permissionId('tag.manage')]]);
        self::assertResponseRedirects('/support/admin/roles');
        static::entityManager()->clear();
        $role = $this->role('tag_readers');
        self::assertSame('Tag Keepers', $role->getName());
        self::assertSame(['tag.manage', 'tag.view'], $this->slugs($role));

        $this->send('POST', '/support/admin/roles', ['name' => '', 'permissions' => [999999]]);
        self::assertResponseStatusCodeSame(422);

        $this->send('DELETE', '/support/admin/roles/'.$this->role('admin')->getId());
        static::entityManager()->clear();
        self::assertNotNull($this->role('admin'), 'system roles cannot be deleted');

        $this->send('DELETE', '/support/admin/roles/'.$role->getId());
        self::assertResponseRedirects('/support/admin/roles');
        static::entityManager()->clear();
        self::assertNull(static::entityManager()->getRepository(Role::class)->findOneBy(['slug' => 'tag_readers']));
    }

    public function testTheSwitchIsNotASetting(): void
    {
        $this->boot(false);
        $this->give($this->admin, 'admin');
        $this->client->loginUser($this->admin);

        $this->send('POST', '/support/admin/settings', ['roles_permissions' => true, 'access.roles_permissions' => '1', 'ESCALATED_ROLES_PERMISSIONS' => 'true']);
        self::assertLessThan(500, $this->client->getResponse()->getStatusCode());

        $this->send('GET', '/support/admin/roles');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheSwitchReadsTheEnvVarWhenNotConfigured(): void
    {
        $_SERVER['ESCALATED_ROLES_PERMISSIONS'] = $_ENV['ESCALATED_ROLES_PERMISSIONS'] = 'true';
        putenv('ESCALATED_ROLES_PERMISSIONS=true');
        try {
            $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
            $this->users();
            $this->client->loginUser($this->admin);

            $this->send('GET', '/support/admin/tags');
            self::assertResponseStatusCodeSame(403, 'enforced: the admin holds no role');

            $this->give($this->admin, 'admin');
            $this->visit('/support/admin/roles');
        } finally {
            unset($_SERVER['ESCALATED_ROLES_PERMISSIONS'], $_ENV['ESCALATED_ROLES_PERMISSIONS']);
            putenv('ESCALATED_ROLES_PERMISSIONS');
        }
    }

    // ---- Roles & permissions (switch on) -----------------------------------

    public function testAStaffUserNeedsTheRoutesPermission(): void
    {
        $this->boot(true);
        $readers = $this->customRole('Tag readers', ['tag.view']);
        $this->give($this->admin, $readers);
        $this->client->loginUser($this->admin);

        // Admin read
        $this->visit('/support/admin/tags');
        $this->send('GET', '/support/admin/departments');
        self::assertResponseStatusCodeSame(403);

        // Admin write
        $this->send('POST', '/support/admin/tags', ['name' => 'urgent', 'color' => '#ff0000']);
        self::assertResponseStatusCodeSame(403);
        $this->give($this->admin, 'admin');
        $this->send('POST', '/support/admin/tags', ['name' => 'urgent', 'color' => '#ff0000']);
        self::assertResponseRedirects();

        // Agent ticket action
        $ticket = $this->ticket();
        $this->client->loginUser($this->agent);
        $this->send('POST', $this->agentTicket($ticket, 'reply'), ['body' => 'Hello']);
        self::assertResponseStatusCodeSame(403);
        $this->give($this->agent, 'agent');
        $this->send('POST', $this->agentTicket($ticket, 'reply'), ['body' => 'Hello']);
        self::assertResponseRedirects();
    }

    public function testALightAgentCanViewAndAddANoteButNothingMore(): void
    {
        $this->boot(true);
        $this->give($this->agent, 'light_agent');
        $ticket = $this->ticket();
        $this->client->loginUser($this->agent);

        $this->visit($this->agentTicket($ticket, ''));
        $this->send('POST', $this->agentTicket($ticket, 'note'), ['body' => 'Internal']);
        self::assertResponseRedirects();

        $denied = [
            'assign' => ['agent_id' => $this->agent->getId()],
            'priority' => ['priority' => 'high'],
            'status' => ['status' => 'closed'],
        ];
        foreach ($denied as $action => $body) {
            $this->send('POST', $this->agentTicket($ticket, $action), $body);
            self::assertResponseStatusCodeSame(403, $action);
        }
        $this->send('POST', '/support/agent/tickets/bulk', ['ticket_ids' => [$ticket->getId()], 'action' => 'delete']);
        self::assertResponseStatusCodeSame(403, 'delete');

        static::entityManager()->clear();
        $fresh = static::entityManager()->find(Ticket::class, $ticket->getId());
        self::assertSame('medium', $fresh?->getPriority());
        self::assertNull($fresh?->getAssignedTo());
        self::assertNull($fresh?->getDeletedAt());
    }

    public function testClosingStatusesNeedTicketClose(): void
    {
        $this->boot(true);
        $editors = $this->customRole('Editors', ['ticket.view', 'ticket.edit']);
        $this->give($this->agent, $editors);
        $ticket = $this->ticket();
        $this->client->loginUser($this->agent);

        $this->send('POST', $this->agentTicket($ticket, 'status'), ['status' => 'in_progress']);
        self::assertResponseRedirects();
        foreach (['resolved', 'closed'] as $status) {
            $this->send('POST', $this->agentTicket($ticket, 'status'), ['status' => $status]);
            self::assertResponseStatusCodeSame(403, $status);
        }

        $this->give($this->agent, 'agent');
        $this->send('POST', $this->agentTicket($ticket, 'status'), ['status' => 'resolved']);
        self::assertResponseRedirects();
        static::entityManager()->clear();
        self::assertSame('resolved', static::entityManager()->find(Ticket::class, $ticket->getId())?->getStatus());
    }

    public function testBulkActionsCheckEachActionsPermission(): void
    {
        $this->boot(true);
        $editors = $this->customRole('Editors', ['ticket.view', 'ticket.edit']);
        $this->give($this->agent, $editors);
        $ticket = $this->ticket();
        $this->client->loginUser($this->agent);

        $bulk = fn (string $action, mixed $value = null) => $this->send('POST', '/support/agent/tickets/bulk', ['ticket_ids' => [$ticket->getId()], 'action' => $action, 'value' => $value]);

        $bulk('priority', 'high');
        self::assertResponseRedirects();
        $bulk('assign', $this->agent->getId());
        self::assertResponseStatusCodeSame(403, 'assign needs ticket.assign');
        $bulk('status', 'closed');
        self::assertResponseStatusCodeSame(403, 'closing needs ticket.close');
        $bulk('delete');
        self::assertResponseStatusCodeSame(403, 'delete needs ticket.delete');

        static::entityManager()->clear();
        $fresh = static::entityManager()->find(Ticket::class, $ticket->getId());
        self::assertSame('high', $fresh?->getPriority());
        self::assertNull($fresh?->getAssignedTo());
    }

    public function testApplyingAMacroNeedsMacroViewAndTicketEdit(): void
    {
        $this->boot(true);
        $this->give($this->agent, 'light_agent');
        $ticket = $this->ticket();
        $this->client->loginUser($this->agent);

        $this->send('POST', $this->agentTicket($ticket, 'macro'), ['macro_id' => 1]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheStaffApiChecksTheTokenOwnersPermissions(): void
    {
        $this->boot(true);
        $ticket = $this->ticket();
        $token = $this->token($this->agent, ['agent']);
        $api = fn (string $method, string $uri, array $body = []) => $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: (string) json_encode($body));
        $create = ['subject' => 'From the API', 'description' => 'Body', 'requester_id' => $this->customer->getId(), 'requester_class' => TestUser::class];

        $api('GET', '/support/api/v1/tickets');
        self::assertResponseStatusCodeSame(403, 'no role, no permissions');

        $this->give($this->agent, 'light_agent');
        $api('GET', '/support/api/v1/tickets');
        self::assertResponseIsSuccessful();
        $api('POST', '/support/api/v1/tickets', $create);
        self::assertResponseStatusCodeSame(403, 'light_agent cannot create');
        $api('POST', '/support/api/v1/tickets/'.$ticket->getReference().'/status', ['status' => 'closed']);
        self::assertResponseStatusCodeSame(403, 'light_agent cannot close');

        $this->give($this->agent, 'agent');
        $api('POST', '/support/api/v1/tickets', $create);
        self::assertResponseStatusCodeSame(201);

        $api('GET', '/support/api/v1/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testEveryStaffRouteIsInThePermissionMap(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class, 'enable_newsletters' => true, 'access' => ['roles_permissions' => true]]]);

        $governed = [];
        $unmapped = [];
        foreach (array_keys(static::getContainer()->get('router')->getRouteCollection()->all()) as $name) {
            if (!RoutePermissions::governs($name)) {
                continue;
            }
            $governed[] = $name;
            if (!RoutePermissions::isMapped($name)) {
                $unmapped[] = $name;
            }
        }

        self::assertGreaterThan(200, \count($governed), 'the router should list every admin, agent and staff API route');
        self::assertContains('escalated.admin.roles.index', $governed);
        self::assertContains('escalated.admin.newsletters.index', $governed);
        self::assertSame([], $unmapped, 'every admin, agent and staff API route needs an entry in RoutePermissions::map()');

        foreach (['escalated.api.mobile.guest.lookup', 'escalated.api.kb.articles', 'escalated.customer.tickets.index', 'escalated.widget.config'] as $notStaff) {
            self::assertFalse(RoutePermissions::governs($notStaff), $notStaff);
        }
    }

    public function testEverySlugTheMapRequiresIsSeeded(): void
    {
        $seeded = array_column(PermissionSeeder::permissions(), 'slug');
        foreach (RoutePermissions::map() as $route => $slugs) {
            foreach ($slugs as $slug) {
                self::assertContains($slug, $seeded, $route);
            }
        }
        foreach (RoutePermissions::BULK_ACTIONS as $slugs) {
            foreach ($slugs as $slug) {
                self::assertContains($slug, $seeded);
            }
        }
    }

    public function testPassingTheAdminGateWithoutARoleGrantsNothing(): void
    {
        $this->boot(true);
        $this->client->loginUser($this->admin);

        foreach (['/support/admin/settings', '/support/admin/roles', '/support/admin/tags', '/support/admin/users', '/support/agent/dashboard', '/support/agent/tickets'] as $uri) {
            $this->send('GET', $uri);
            self::assertResponseStatusCodeSame(403, $uri);
        }
    }

    public function testWithTheSwitchOffTheSameRequestsSucceed(): void
    {
        $this->boot(false);
        $this->give($this->agent, 'light_agent');
        $ticket = $this->ticket();

        $this->client->loginUser($this->admin);
        $this->visit('/support/admin/tags');
        $this->visit('/support/admin/settings');
        self::assertSame(['*'], $this->shared()['permissions']);

        $this->client->loginUser($this->agent);
        $this->send('POST', $this->agentTicket($ticket, 'priority'), ['priority' => 'high']);
        self::assertResponseRedirects();
        $this->send('POST', $this->agentTicket($ticket, 'status'), ['status' => 'closed']);
        self::assertResponseRedirects();
        self::assertSame(['*'], $this->shared()['permissions']);
    }

    // ---- Newsletters: the voter follows the switch -------------------------

    public function testNewslettersFollowTheAdminGateWhileTheSwitchIsOff(): void
    {
        $this->boot(false, newsletters: true);
        $this->client->loginUser($this->admin);
        $this->visit('/support/admin/newsletters');

        $manager = new TestUser('newsletters@example.com', ['ROLE_ESCALATED_NEWSLETTERS_MANAGE']);
        static::entityManager()->persist($manager);
        static::entityManager()->flush();
        $this->client->loginUser($manager);
        $this->send('GET', '/support/admin/newsletters');
        self::assertResponseStatusCodeSame(403, 'a host role is not the admin gate');
    }

    public function testNewslettersNeedTheirPermissionWhileTheSwitchIsOn(): void
    {
        $this->boot(true, newsletters: true);
        $this->give($this->admin, $this->customRole('Ticket readers', ['ticket.view']));
        $this->client->loginUser($this->admin);

        $this->send('GET', '/support/admin/newsletters');
        self::assertResponseStatusCodeSame(403);

        $this->give($this->admin, 'admin');
        $this->visit('/support/admin/newsletters');
    }

    // ---- Giving users roles ------------------------------------------------

    public function testTheUsersPageAndEndpointManageRoles(): void
    {
        $this->boot(true);
        $this->give($this->admin, 'admin');
        $this->give($this->agent, 'light_agent');
        $this->client->loginUser($this->admin);

        $page = $this->visit('/support/admin/users');
        $rows = array_column($page['props']['users']['data'], null, 'email');
        self::assertSame(['light_agent'], $rows['agent@example.com']['roles']);
        self::assertSame([], $rows['customer@example.com']['roles']);
        self::assertSame(['Admin', 'Agent', 'Light Agent'], array_column($page['props']['roles'], 'name'));

        $agentRole = $this->role('agent');
        $this->send('PUT', '/support/admin/users/'.$this->agent->getId().'/roles', ['role_ids' => [$agentRole->getId()]]);
        self::assertResponseRedirects();
        self::assertSame(['agent'], $this->roleSlugsOf($this->agent));

        // You cannot take away your own role management.
        $this->send('PUT', '/support/admin/users/'.$this->admin->getId().'/roles', ['role_ids' => [$agentRole->getId()]]);
        self::assertResponseRedirects();
        self::assertSame(['admin'], $this->roleSlugsOf($this->admin));
    }

    public function testTheCommandsSeedAndAssignRoles(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $this->users();
        $application = new Application(static::$kernel);

        $seed = new CommandTester($application->find('escalated:permissions:seed'));
        self::assertSame(0, $seed->execute([]));
        self::assertSame(0, $seed->execute([]), 'seeding again is safe');
        self::assertCount(\count(PermissionSeeder::permissions()), static::entityManager()->getRepository(Permission::class)->findAll());
        self::assertCount(\count(PermissionSeeder::permissions()), $this->role('admin')->getPermissions());

        $assign = new CommandTester($application->find('escalated:roles:assign'));
        self::assertSame(0, $assign->execute(['user' => 'admin@example.com']));
        self::assertSame(0, $assign->execute(['user' => (string) $this->agent->getId(), 'role' => 'light_agent']));
        self::assertSame(1, $assign->execute(['user' => 'nobody@example.com']));
        self::assertSame(1, $assign->execute(['user' => 'admin@example.com', 'role' => 'no_such_role']));

        self::assertSame(['admin'], $this->roleSlugsOf($this->admin));
        self::assertSame(['light_agent'], $this->roleSlugsOf($this->agent));
    }

    // ---- Helpers -------------------------------------------------------------

    private function boot(bool $rolesOn, bool $newsletters = false): void
    {
        $config = ['user_class' => TestUser::class, 'access' => ['roles_permissions' => $rolesOn]];
        if ($newsletters) {
            $config['enable_newsletters'] = true;
        }
        $this->client = static::clientWithSchema(['escalated' => $config]);
        $this->users();
    }

    private function users(): void
    {
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
        $em->flush();

        $seeder = static::getContainer()->get(PermissionSeeder::class);
        \assert($seeder instanceof PermissionSeeder);
        $seeder->seed();
    }

    private function give(TestUser $user, Role|string $role): void
    {
        $role = \is_string($role) ? $this->role($role) : $role;
        $em = static::entityManager();
        $em->persist(new RoleUser((int) $user->getId(), $role));
        $em->flush();
    }

    /**
     * @param list<string> $slugs
     */
    private function customRole(string $name, array $slugs): Role
    {
        $em = static::entityManager();
        $role = new Role($name, Role::slugFor($name));
        $role->syncPermissions($em->getRepository(Permission::class)->findBy(['slug' => $slugs]));
        $em->persist($role);
        $em->flush();

        return $role;
    }

    private function role(string $slug): Role
    {
        $role = static::entityManager()->getRepository(Role::class)->findOneBy(['slug' => $slug]);
        self::assertInstanceOf(Role::class, $role, 'no role '.$slug);

        return $role;
    }

    private function permissionId(string $slug): int
    {
        return (int) static::entityManager()->getRepository(Permission::class)->findOneBy(['slug' => $slug])?->getId();
    }

    /**
     * @return list<string>
     */
    private function slugs(Role $role): array
    {
        $slugs = array_map(static fn (Permission $p): string => $p->getSlug(), $role->getPermissions()->toArray());
        sort($slugs);

        return array_values($slugs);
    }

    /**
     * @return list<string>
     */
    private function roleSlugsOf(TestUser $user): array
    {
        static::entityManager()->clear();
        $slugs = array_map(
            static fn (RoleUser $held): string => $held->getRole()->getSlug(),
            static::entityManager()->getRepository(RoleUser::class)->findBy(['userId' => $user->getId()]),
        );
        sort($slugs);

        return $slugs;
    }

    private function ticket(): Ticket
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);
        $ticket = $service->create([
            'subject' => 'Printer on fire',
            'description' => 'It is on fire',
            'requester_id' => $this->customer->getId(),
            'requester_class' => TestUser::class,
        ]);
        static::entityManager()->refresh($ticket);

        return $ticket;
    }

    private function agentTicket(Ticket $ticket, string $action): string
    {
        return rtrim('/support/agent/tickets/'.$ticket->getReference().'/'.$action, '/');
    }

    /**
     * @param list<string> $abilities
     */
    private function token(TestUser $user, array $abilities): string
    {
        $service = static::getContainer()->get(ApiTokenService::class);
        \assert($service instanceof ApiTokenService);

        return $service->createToken((int) $user->getId(), 'test', $abilities)['plainTextToken'];
    }

    /**
     * @return array<string, mixed>
     */
    private function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful($uri);
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function send(string $method, string $uri, array $body = []): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: (string) json_encode($body));
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
