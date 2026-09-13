<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Api;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\ApiTokenService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The JSON API used to serve anyone who simply left out the Authorization
 * header: the bearer authenticator treated "no header" as "carry on", and
 * none of the ticket endpoints checked who was asking. The ticket index
 * listed every ticket, store/update/status/subjects wrote, and the custom
 * action endpoint crashed on a null user.
 *
 * Laravel's API answers 401 without a token and requires agent access. The
 * Symfony API also accepts a signed-in session user, so the rule here is: an
 * authenticated principal (bearer token or session) or 401; an agent or 403.
 */
final class ApiAuthenticationTest extends EscalatedWebTestCase
{
    private const OPTIONS = [
        'escalated' => [
            'ticket_actions' => [['key' => 'sync-crm', 'label' => 'Sync CRM']],
        ],
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(self::OPTIONS);
    }

    public function testTheTicketIndexRejectsARequestWithoutCredentials(): void
    {
        $this->ticket('Somebody else\'s problem');

        $this->client->jsonRequest('GET', '/support/api/v1/tickets');

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['message' => 'Unauthenticated.'], $this->json());
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function writeEndpoints(): iterable
    {
        yield 'store' => ['POST', '/support/api/v1/tickets', ['subject' => 'Created anonymously']];
        yield 'show' => ['GET', '/support/api/v1/tickets/{ref}', []];
        yield 'update' => ['PATCH', '/support/api/v1/tickets/{ref}', ['subject' => 'Renamed anonymously']];
        yield 'status' => ['POST', '/support/api/v1/tickets/{ref}/status', ['status' => 'closed']];
        yield 'rating' => ['POST', '/support/api/v1/tickets/{ref}/rating', ['rating' => 5]];
        yield 'custom action' => ['POST', '/support/api/v1/tickets/{ref}/actions/sync-crm', []];
        yield 'attach subject' => ['POST', '/support/api/v1/tickets/{ref}/subjects', ['type' => 'App\Entity\Project', 'id' => '1']];
        yield 'detach subject' => ['DELETE', '/support/api/v1/tickets/{ref}/subjects/1', []];
        yield 'auth me' => ['GET', '/support/api/v1/auth/me', []];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('writeEndpoints')]
    public function testTicketEndpointsRejectARequestWithoutCredentials(string $method, string $path, array $body): void
    {
        $ticket = $this->ticket('Untouched', Ticket::STATUS_RESOLVED);
        $reference = $ticket->getReference();

        $this->client->jsonRequest($method, str_replace('{ref}', $reference, $path), $body);

        self::assertResponseStatusCodeSame(401);

        $em = static::entityManager();
        $em->clear();
        $reloaded = $em->getRepository(Ticket::class)->findOneBy(['reference' => $reference]);
        self::assertNotNull($reloaded);
        self::assertSame('Untouched', $reloaded->getSubject());
        self::assertSame(Ticket::STATUS_RESOLVED, $reloaded->getStatus());
        self::assertSame(1, $em->getRepository(Ticket::class)->count([]));
        self::assertSame(0, $em->getRepository(SatisfactionRating::class)->count([]));
    }

    public function testAnAgentTokenCanListTickets(): void
    {
        $agent = $this->user('agent@example.com');
        $this->agentProfile($agent);
        $this->ticket('Visible to agents');

        $this->client->jsonRequest('GET', '/support/api/v1/tickets', [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token($agent, ['agent']),
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json()['data']);
    }

    public function testATokenWhoseOwnerIsNoLongerAnAgentIsForbidden(): void
    {
        $formerAgent = $this->user('former@example.com');
        $this->ticket('Not for former agents');

        $this->client->jsonRequest('GET', '/support/api/v1/tickets', [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token($formerAgent, ['agent']),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testABearerTokenDoesNotOutliveItsRequestAsASession(): void
    {
        $agent = $this->user('bearer-only@example.com');
        $this->agentProfile($agent);

        $this->client->jsonRequest('GET', '/support/api/v1/tickets', [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token($agent, ['agent']),
        ]);
        self::assertResponseIsSuccessful();

        // Same client, same cookie jar, no Authorization header.
        $this->client->jsonRequest('GET', '/support/api/v1/tickets');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnInvalidTokenIsRejected(): void
    {
        $this->client->jsonRequest('GET', '/support/api/v1/tickets', [], [
            'HTTP_AUTHORIZATION' => 'Bearer not-a-real-token',
        ]);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['message' => 'Invalid token.'], $this->json());
    }

    public function testASignedInAgentCanUseTheApiWithTheirSession(): void
    {
        $agent = $this->user('session-agent@example.com');
        $this->agentProfile($agent);
        $this->ticket('Visible in the session');

        $this->client->loginUser($agent);
        $this->client->jsonRequest('GET', '/support/api/v1/tickets');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->json()['data']);
    }

    public function testASignedInUserWhoIsNotAnAgentIsForbidden(): void
    {
        $customer = $this->user('customer@example.com');
        $this->ticket('Not theirs to list');

        $this->client->loginUser($customer);
        $this->client->jsonRequest('GET', '/support/api/v1/tickets');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAgentCanTriggerACustomAction(): void
    {
        $agent = $this->user('actions@example.com');
        $this->agentProfile($agent);
        $ticket = $this->ticket('Needs a CRM sync');

        $this->client->jsonRequest('POST', '/support/api/v1/tickets/'.$ticket->getReference().'/actions/sync-crm', [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token($agent, ['agent']),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('sync-crm', $this->json()['action']);
    }

    public function testTheRequesterCanRateTheirOwnResolvedTicket(): void
    {
        $customer = $this->user('rater@example.com');
        $ticket = $this->ticket('Resolved for the requester', Ticket::STATUS_RESOLVED, $customer);

        $this->client->loginUser($customer);
        $this->client->jsonRequest('POST', '/support/api/v1/tickets/'.$ticket->getReference().'/rating', ['rating' => 4]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testACustomerCannotRateSomebodyElsesTicket(): void
    {
        $owner = $this->user('owner@example.com');
        $stranger = $this->user('stranger@example.com');
        $ticket = $this->ticket('Resolved for the owner', Ticket::STATUS_RESOLVED, $owner);

        $this->client->loginUser($stranger);
        $this->client->jsonRequest('POST', '/support/api/v1/tickets/'.$ticket->getReference().'/rating', ['rating' => 1]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, static::entityManager()->getRepository(SatisfactionRating::class)->count([]));
    }

    public function testThePublicKnowledgeBaseApiNeedsNoCredentials(): void
    {
        $this->client->jsonRequest('GET', '/support/api/v1/kb/articles');

        self::assertResponseIsSuccessful();
    }

    private function user(string $email): TestUser
    {
        $user = new TestUser($email);
        $em = static::entityManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function agentProfile(TestUser $user): void
    {
        $profile = (new AgentProfile())->setUserId((int) $user->getId());
        $em = static::entityManager();
        $em->persist($profile);
        $em->flush();
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

    private function ticket(string $subject, string $status = Ticket::STATUS_OPEN, ?TestUser $requester = null): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject($subject)
            ->setStatus($status)
            ->setReference('ESC-'.bin2hex(random_bytes(4)));

        if (null !== $requester) {
            $ticket->setRequesterId((int) $requester->getId())->setRequesterClass(TestUser::class);
        }

        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
