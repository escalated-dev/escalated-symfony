<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * `routes.customer_guards`: in an install where only staff use Escalated,
 * the customer portal and customer knowledge base accepted any signed-in
 * user, so staff never given Escalated access could list and raise tickets.
 * The host's extra security attributes now apply to every
 * `escalated.customer.*` route; with none set, nothing changes.
 */
final class CustomerGuardsTest extends EscalatedWebTestCase
{
    private const CUSTOMER_PAGES = ['/support/customer/tickets', '/support/customer/tickets/create', '/support/kb'];

    private KernelBrowser $client;
    private TestUser $agent;
    private TestUser $outsider;

    public function testByDefaultAnySignedInUserReachesTheCustomerPages(): void
    {
        $this->boot([]);
        $this->client->loginUser($this->outsider);

        foreach (self::CUSTOMER_PAGES as $uri) {
            $this->visit($uri);
            self::assertResponseIsSuccessful($uri);
        }
    }

    public function testAUserWhoFailsTheGuardIsRefusedOnCustomerAndKnowledgeBaseRoutes(): void
    {
        $this->boot(['routes' => ['customer_guards' => ['ESCALATED_AGENT']]]);
        $this->client->loginUser($this->outsider);

        foreach (self::CUSTOMER_PAGES as $uri) {
            $this->visit($uri);
            self::assertResponseStatusCodeSame(403, $uri);
        }

        // Writes too.
        $this->client->request('POST', '/support/customer/tickets', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['subject' => 'Let me in', 'description' => 'Please']));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAUserWhoPassesTheGuardStillGetsIn(): void
    {
        $this->boot(['routes' => ['customer_guards' => ['ESCALATED_AGENT']]]);
        $this->client->loginUser($this->agent);

        foreach (self::CUSTOMER_PAGES as $uri) {
            $this->visit($uri);
            self::assertResponseIsSuccessful($uri);
        }
    }

    public function testTheGuardsLeaveStaffRoutesAlone(): void
    {
        $this->boot(['routes' => ['customer_guards' => ['ROLE_NOBODY_HAS']]]);
        $this->client->loginUser($this->agent);

        $this->visit('/support/agent/tickets');
        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, mixed> $escalated
     */
    private function boot(array $escalated): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class] + $escalated]);
        $em = static::entityManager();
        $this->agent = new TestUser('agent@example.com');
        $this->outsider = new TestUser('staff-without-access@example.com');
        $em->persist($this->agent);
        $em->persist($this->outsider);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $this->agent->getId()));
        $em->flush();
    }

    private function visit(string $uri): void
    {
        $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
    }
}
