<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Agent;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\ChatRoutingRule;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Webhook;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Http\WebhookHttpClientInterface;
use Escalated\Symfony\Service\WorkflowEngine;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Escalated\Symfony\Tests\Service\RecordingWebhookHttpClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Live chat transfer, as escalated-laravel's ChatController::transfer and
 * ChatSessionService::transferChat: `escalated.admin.chat.transfer` takes the
 * chat session's id (the shared Agent/TicketShow page passes
 * `chat_session_id`) and a body of `{agent_id, department_id}`; the shared
 * ChatActionBar's `{type, id}` is read too. It emits `chat.transferred`.
 */
final class ChatTransferTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;

    private RecordingWebhookHttpClient $http;

    protected function setUp(): void
    {
        if ('testAnAgentWhoCannotAccessTheTicketIsRefused' === $this->name()) {
            return;
        }
        $this->boot();
    }

    /**
     * @param array<string, mixed> $options
     */
    private function boot(array $options = []): void
    {
        $this->client = static::clientWithSchema($options);
        $this->http = RecordingWebhookHttpClient::alwaysReturns(200, 'ok');
        static::getContainer()->set(WebhookHttpClientInterface::class, $this->http);
    }

    public function testTheRouteHasLaravelsName(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        self::assertSame('/support/agent/chat/7/transfer', $router->generate('escalated.admin.chat.transfer', ['session' => 7]));
    }

    public function testTransferToAnAgentReassignsTheChatAndTicket(): void
    {
        $from = $this->agent('from@example.com');
        $to = $this->agent('to@example.com');
        $session = $this->chat($from);
        $this->webhook();
        $this->workflow();
        $this->client->loginUser($from);

        $this->transfer($session, ['agent_id' => $to->getId()]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('Chat transferred.', $body['message']);
        self::assertSame($to->getId(), $body['session']['agent_id']);

        [$session, $ticket] = $this->reload($session);
        self::assertSame($to->getId(), $session->getAgentId());
        self::assertSame(ChatSession::STATUS_ACTIVE, $session->getStatus());
        self::assertSame($to->getId(), $ticket->getAssignedTo());

        self::assertSame(['chat.transferred'], $this->deliveredEvents());
        $payload = $this->payload(0);
        self::assertSame($ticket->getId(), $payload['ticket']['id']);
        self::assertSame($session->getId(), $payload['chat_session_id']);
        self::assertSame($from->getId(), $payload['from_agent_id']);
        self::assertSame($to->getId(), $payload['to_agent_id']);
        self::assertNull($payload['to_department_id']);
        self::assertStringNotContainsString((string) $ticket->getGuestToken(), $this->http->calls[0]['body']);

        self::assertSame(['transferred'], $this->notes($ticket));
    }

    public function testDepartmentOnlyTransferRequeuesAndReroutes(): void
    {
        $from = $this->agent('from@example.com');
        $online = $this->agent('online@example.com');
        $department = $this->department('Billing');
        $rule = (new ChatRoutingRule())
            ->setDepartment($department)
            ->setAgentIds([$online->getId()])
            ->setName('Billing queue')
            ->setStrategy(ChatRoutingRule::STRATEGY_ROUND_ROBIN);
        $em = static::entityManager();
        $em->persist($rule);
        $em->flush();
        $session = $this->chat($from);
        $this->webhook();
        $this->client->loginUser($from);

        $this->transfer($session, ['department_id' => $department->getId()]);

        self::assertResponseIsSuccessful();
        [$session, $ticket] = $this->reload($session);
        self::assertSame($department->getId(), $ticket->getDepartment()?->getId());
        self::assertSame($online->getId(), $session->getAgentId(), 'routing should pick an agent in the new department');
        self::assertSame($online->getId(), $ticket->getAssignedTo());

        self::assertSame(['chat.transferred', 'chat.assigned'], $this->deliveredEvents());
        $payload = $this->payload(0);
        self::assertSame($from->getId(), $payload['from_agent_id']);
        self::assertNull($payload['to_agent_id']);
        self::assertSame($department->getId(), $payload['to_department_id']);
    }

    public function testDepartmentOnlyTransferWithNoAgentLeavesTheChatWaiting(): void
    {
        $from = $this->agent('from@example.com');
        $department = $this->department('Billing');
        $session = $this->chat($from);
        $this->client->loginUser($from);

        $this->transfer($session, ['department_id' => $department->getId()]);

        self::assertResponseIsSuccessful();
        [$session, $ticket] = $this->reload($session);
        self::assertNull($session->getAgentId());
        self::assertSame(ChatSession::STATUS_WAITING, $session->getStatus());
        self::assertNull($ticket->getAssignedTo());
        self::assertSame($department->getId(), $ticket->getDepartment()?->getId());
    }

    public function testAgentAndDepartmentTogether(): void
    {
        $from = $this->agent('from@example.com');
        $to = $this->agent('to@example.com');
        $department = $this->department('Billing');
        $session = $this->chat($from);
        $this->client->loginUser($from);

        $this->transfer($session, ['agent_id' => (string) $to->getId(), 'department_id' => $department->getId()]);

        self::assertResponseIsSuccessful();
        [$session, $ticket] = $this->reload($session);
        self::assertSame($to->getId(), $session->getAgentId());
        self::assertSame(ChatSession::STATUS_ACTIVE, $session->getStatus());
        self::assertSame($department->getId(), $ticket->getDepartment()?->getId());
    }

    public function testTheChatActionBarBodyIsAccepted(): void
    {
        $from = $this->agent('from@example.com');
        $to = $this->agent('to@example.com');
        $department = $this->department('Billing');
        $session = $this->chat($from);
        $this->client->loginUser($from);

        $this->transfer($session, ['type' => 'agent', 'id' => $to->getId()]);
        self::assertResponseIsSuccessful();
        [$reloaded] = $this->reload($session);
        self::assertSame($to->getId(), $reloaded->getAgentId());

        $this->transfer($reloaded, ['type' => 'department', 'id' => $department->getId()]);
        self::assertResponseIsSuccessful();
        [, $ticket] = $this->reload($reloaded);
        self::assertSame($department->getId(), $ticket->getDepartment()?->getId());
    }

    public function testRefusals(): void
    {
        $from = $this->agent('from@example.com');
        $session = $this->chat($from);
        $this->webhook();

        $this->transfer($session, ['department_id' => 1]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403], 'anonymous');

        $customer = new TestUser('customer@example.com');
        $em = static::entityManager();
        $em->persist($customer);
        $em->flush();
        $this->client->loginUser($customer);
        $this->transfer($session, ['department_id' => 1]);
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($from);
        $this->transfer($session, []);
        self::assertResponseStatusCodeSame(422);
        $this->transfer($session, ['agent_id' => null, 'department_id' => '']);
        self::assertResponseStatusCodeSame(422);
        $this->transfer($session, ['department_id' => 999999]);
        self::assertResponseStatusCodeSame(422);
        $this->transfer($session, ['department_id' => 'billing']);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/support/agent/chat/999999/transfer', ['department_id' => 1]);
        self::assertResponseStatusCodeSame(404);

        $to = $this->agent('to@example.com');
        [$session] = $this->reload($session);
        $session->setStatus(ChatSession::STATUS_ENDED);
        static::entityManager()->flush();
        $this->transfer($session, ['agent_id' => $to->getId()]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame([], $this->deliveredEvents());
    }

    public function testAnAgentWhoCannotAccessTheTicketIsRefused(): void
    {
        $this->boot(['escalated' => ['visibility' => 'department', 'user_class' => TestUser::class]]);
        $from = $this->agent('from@example.com');
        $outsider = $this->agent('outsider@example.com');
        $department = $this->department('Billing');
        $em = static::entityManager();
        $em->persist(new \Escalated\Symfony\Entity\DepartmentAgent($department, $from->getId()));
        $em->flush();
        $session = $this->chat($from, $department);
        $this->webhook();
        $this->client->loginUser($from);

        $this->transfer($session, ['agent_id' => $outsider->getId()]);

        self::assertResponseStatusCodeSame(422);
        [$session, $ticket] = $this->reload($session);
        self::assertSame($from->getId(), $session->getAgentId());
        self::assertSame([], $this->deliveredEvents());
    }

    public function testChatTransferredIsOfferedToWebhooksAndWorkflows(): void
    {
        self::assertContains(EventNames::CHAT_TRANSFERRED, EventNames::EMITTED);
        self::assertContains('chat.transferred', WorkflowEngine::TRIGGER_EVENTS);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function transfer(ChatSession $session, array $body): void
    {
        $this->client->jsonRequest('POST', '/support/agent/chat/'.$session->getId().'/transfer', $body);
    }

    private function agent(string $email): TestUser
    {
        $user = new TestUser($email);
        $em = static::entityManager();
        $em->persist($user);
        $em->flush();
        $profile = new AgentProfile();
        $profile->setUserId($user->getId());
        $em->persist($profile);
        $em->flush();

        return $user;
    }

    private function department(string $name): Department
    {
        $department = (new Department())->setName($name)->setSlug(strtolower($name).'-'.bin2hex(random_bytes(3)));
        $em = static::entityManager();
        $em->persist($department);
        $em->flush();

        return $department;
    }

    private function chat(TestUser $agent, ?Department $department = null): ChatSession
    {
        $ticket = (new Ticket())
            ->setSubject('Live Chat')
            ->setChannel(Ticket::CHANNEL_CHAT)
            ->setStatus(Ticket::STATUS_LIVE)
            ->setGuestName('Ada')
            ->setGuestToken('chat-token-'.bin2hex(random_bytes(8)))
            ->setReference('ESC-C'.bin2hex(random_bytes(4)))
            ->setAssignedTo($agent->getId());
        if (null !== $department) {
            $ticket->setDepartment($department);
        }
        $em = static::entityManager();
        $em->persist($ticket);
        $session = new ChatSession();
        $session->setTicket($ticket);
        $session->setAgentId($agent->getId());
        $session->setStatus(ChatSession::STATUS_ACTIVE);
        $em->persist($session);
        $em->flush();

        return $session;
    }

    private function webhook(): void
    {
        $em = static::entityManager();
        $em->persist((new Webhook())->setUrl('https://93.184.215.14/hooks')->setEvents(['chat.transferred', 'chat.assigned'])->setActive(true));
        $em->flush();
    }

    private function workflow(): void
    {
        $em = static::entityManager();
        $em->persist((new Workflow())
            ->setName('On transfer')
            ->setTriggerEvent('chat.transferred')
            ->setPosition(1)
            ->setConditions(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'Live Chat']]])
            ->setActions([['type' => 'add_note', 'value' => 'transferred']]));
        $em->flush();
    }

    /**
     * @return array{0: ChatSession, 1: Ticket}
     */
    private function reload(ChatSession $session): array
    {
        $em = static::entityManager();
        $em->clear();
        $fresh = $em->getRepository(ChatSession::class)->find($session->getId());
        self::assertNotNull($fresh);

        return [$fresh, $fresh->getTicket()];
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($body);

        return $body;
    }

    /** @return list<string> */
    private function deliveredEvents(): array
    {
        return array_map(
            static fn (array $call): string => json_decode($call['body'], true)['event'],
            $this->http->calls,
        );
    }

    /** @return array<string, mixed> */
    private function payload(int $index): array
    {
        return json_decode($this->http->calls[$index]['body'], true)['payload'];
    }

    /** @return list<string> */
    private function notes(Ticket $ticket): array
    {
        $rows = static::entityManager()->getConnection()->fetchFirstColumn(
            'SELECT body FROM escalated_replies WHERE ticket_id = ? AND is_internal_note = ? AND author_id IS NULL ORDER BY id',
            [$ticket->getId(), true],
            [\Doctrine\DBAL\ParameterType::INTEGER, \Doctrine\DBAL\ParameterType::BOOLEAN],
        );

        return array_values(array_map('strval', $rows));
    }
}
