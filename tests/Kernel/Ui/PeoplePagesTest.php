<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\ApiToken;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Escalated/Admin/ApiTokens/Index and Admin/Users/Index against what the
 * shared pages read (escalated-laravel's ApiTokenController and
 * UserController). The token page read `users` for its owner picker and
 * `api_enabled`, and showed each token's `user_name`; none were sent. A
 * token made for "myself" was owned by the admin's login name rather than
 * their user key, so it authenticated as nobody.
 */
final class PeoplePagesTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $admin;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($this->admin);
        $em->persist(new TestUser('customer@example.com'));
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $this->admin->getId()));
        $em->flush();
        $this->client->loginUser($this->admin);
    }

    public function testApiTokensPageGetsOwnersAndTheOwnerPicker(): void
    {
        $this->send('POST', '/support/admin/api-tokens', ['name' => 'CI', 'abilities' => ['*'], 'expires_in_days' => '']);
        self::assertResponseRedirects();

        $token = static::entityManager()->getRepository(ApiToken::class)->findOneBy(['name' => 'CI']);
        self::assertSame((string) $this->admin->getId(), $token?->getUserId());

        $page = $this->visit('/support/admin/api-tokens');
        self::assertSame('Escalated/Admin/ApiTokens/Index', $page['component']);
        self::assertTrue($page['props']['api_enabled']);
        self::assertSame([['id' => $this->admin->getId(), 'name' => 'admin@example.com', 'email' => 'admin@example.com']], $page['props']['users']);
        $row = $page['props']['tokens'][0];
        foreach (['id', 'name', 'user_name', 'user_email', 'abilities', 'last_used_at', 'expires_at', 'is_expired', 'created_at'] as $key) {
            self::assertArrayHasKey($key, $row);
        }
        self::assertSame('admin@example.com', $row['user_name']);
        self::assertArrayNotHasKey('token', $row);
    }

    public function testUsersPageListsHostUsersWithTheirRoles(): void
    {
        $page = $this->visit('/support/admin/users');
        self::assertSame('Escalated/Admin/Users/Index', $page['component']);
        self::assertSame(['users', 'filters', 'currentUserId'], array_keys($page['props']));
        self::assertSame(['admin@example.com', 'customer@example.com'], array_column($page['props']['users']['data'], 'email'));
        self::assertSame(['id', 'name', 'email', 'is_admin', 'is_agent'], array_keys($page['props']['users']['data'][0]));
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
