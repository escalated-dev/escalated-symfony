<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Embed;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Permission;
use Escalated\Symfony\Entity\Role;
use Escalated\Symfony\Entity\RoleUser;
use Escalated\Symfony\Security\PermissionSeeder;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Embed\HostAdminVoter;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A host that embeds Escalated's admin in its own admin panel
 * (ADR 2026-11-05-embeddable-admin), through the real HTTP stack: the host's
 * firewall, its HOST_ADMIN voter as the embed guard, and its security headers.
 */
abstract class EmbedTestCase extends EscalatedWebTestCase
{
    protected KernelBrowser $client;

    /** Escalated admin and host admin, and an agent. */
    protected TestUser $admin;

    /** Host admin only: passes the host guard, not Escalated's admin gate. */
    protected TestUser $hostAdmin;

    /** Escalated admin only: refused by the host guard. */
    protected TestUser $escalatedAdmin;

    protected TestUser $agent;

    /**
     * @param array<string, mixed> $embed  the `ui.embed` block
     * @param array<string, mixed> $config more of the `escalated:` block
     */
    protected function boot(array $embed, array $config = [], bool $seedPermissions = false): void
    {
        $this->client = static::clientWithSchema([
            'escalated' => ['user_class' => TestUser::class, 'ui' => ['embed' => $embed], ...$config],
            'embed_host' => true,
        ]);
        $this->users($seedPermissions);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function layoutEmbed(array $overrides = []): array
    {
        return [
            'enabled' => true,
            'guards' => [HostAdminVoter::ATTRIBUTE],
            'layout' => 'Layouts/AdminLayout',
            ...$overrides,
        ];
    }

    protected function users(bool $seedPermissions = false): void
    {
        $em = static::entityManager();
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN', HostAdminVoter::ROLE]);
        $this->hostAdmin = new TestUser('host-admin@example.com', [HostAdminVoter::ROLE]);
        $this->escalatedAdmin = new TestUser('escalated-admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $this->agent = new TestUser('agent@example.com');
        foreach ([$this->admin, $this->hostAdmin, $this->escalatedAdmin, $this->agent] as $user) {
            $em->persist($user);
        }
        $em->flush();
        foreach ([$this->admin, $this->agent] as $user) {
            $em->persist((new AgentProfile())->setUserId((int) $user->getId()));
        }
        $em->flush();

        if ($seedPermissions) {
            $seeder = static::getContainer()->get(PermissionSeeder::class);
            \assert($seeder instanceof PermissionSeeder);
            $seeder->seed();
        }
    }

    /**
     * Give the user a role holding every permission except `$except`.
     *
     * @param list<string> $except
     */
    protected function giveAllPermissionsExcept(TestUser $user, array $except): void
    {
        $em = static::entityManager();
        $role = new Role('Embedded admin '.$user->getId(), 'embedded_admin_'.$user->getId());
        $role->syncPermissions(array_values(array_filter(
            $em->getRepository(Permission::class)->findAll(),
            static fn (Permission $permission): bool => !\in_array($permission->getSlug(), $except, true),
        )));
        $em->persist($role);
        $em->persist(new RoleUser((int) $user->getId(), $role));
        $em->flush();
    }

    /**
     * An Inertia visit; the page object.
     *
     * @return array<string, mixed>
     */
    protected function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful($uri);
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page, $uri);

        return $page;
    }

    protected function statusOf(string $uri, string $method = 'GET'): int
    {
        $this->client->request($method, $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * The data Escalated shared with the last request's page.
     *
     * @return array<string, mixed>
     */
    protected function shared(): array
    {
        $shared = $this->client->getRequest()->attributes->get('_escalated_shared');
        self::assertIsArray($shared);

        return $shared;
    }
}
