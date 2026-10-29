<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Plugins;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Plugin;
use Escalated\Symfony\Plugin\PluginRegistry;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Kernel\Fixtures\Plugin\PluginRecorder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The plugin contract's port test checklist runs through the real router,
 * firewall and controllers, with the fixture native plugins (and, in
 * PluginBridgeTest, a stand-in plugin runtime).
 */
abstract class PluginTestCase extends EscalatedWebTestCase
{
    protected KernelBrowser $client;

    /**
     * @param array<string, mixed> $escalated
     */
    protected function boot(array $escalated = []): void
    {
        PluginRecorder::reset();
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class] + $escalated]);
    }

    protected function tearDown(): void
    {
        PluginRecorder::reset();
        parent::tearDown();
    }

    protected function registry(): PluginRegistry
    {
        $registry = static::getContainer()->get(PluginRegistry::class);
        \assert($registry instanceof PluginRegistry);

        return $registry;
    }

    /**
     * @param list<string> $roles
     */
    protected function user(string $email, array $roles = [], bool $agent = false): TestUser
    {
        $user = new TestUser($email, $roles);
        $em = static::entityManager();
        $em->persist($user);
        $em->flush();
        if ($agent) {
            $em->persist((new AgentProfile())->setUserId((int) $user->getId()));
            $em->flush();
        }

        return $user;
    }

    /**
     * @param list<string> $roles
     */
    protected function loginAdmin(array $roles = []): TestUser
    {
        $admin = $this->user('admin@example.com', ['ROLE_ESCALATED_ADMIN', ...$roles], agent: true);
        $this->client->loginUser($admin);

        return $admin;
    }

    protected function loginAgent(): TestUser
    {
        $agent = $this->user('agent@example.com', [], agent: true);
        $this->client->loginUser($agent);

        return $agent;
    }

    protected function loginCustomer(): TestUser
    {
        $customer = $this->user('customer@example.com');
        $this->client->loginUser($customer);

        return $customer;
    }

    /**
     * An Inertia visit.
     *
     * @return array{component: string, props: array<string, mixed>}
     */
    protected function visit(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);

        return $page;
    }

    /**
     * An Inertia form submission.
     *
     * @param array<string, mixed> $body
     */
    protected function inertia(string $method, string $uri, array $body = []): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
        ], content: (string) json_encode($body));
    }

    /**
     * @return array<string, mixed>|list<mixed>
     */
    protected function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body, (string) $this->client->getResponse()->getContent());

        return $body;
    }

    /**
     * @return list<string>
     */
    protected function flashes(string $type): array
    {
        return $this->client->getRequest()->getSession()->getFlashBag()->peek($type);
    }

    protected function row(string $slug): ?Plugin
    {
        $em = static::entityManager();
        $em->clear();

        return $em->getRepository(Plugin::class)->findOneBy(['slug' => $slug]);
    }

    protected function activate(string $slug): void
    {
        self::assertTrue($this->registry()->activate($slug));
        PluginRecorder::$calls = [];
    }
}
