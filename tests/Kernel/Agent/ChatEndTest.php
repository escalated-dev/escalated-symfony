<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Agent;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Routing\RouterInterface;

/**
 * Ending a live chat from the shared Agent/TicketShow page: it POSTs `{}` to
 * `route('escalated.admin.chat.end', ticket.chat_session_id)`, so the route
 * takes the chat SESSION id (escalated-laravel's
 * `POST admin/chat/{session}/end`). The older ticket-id endpoint
 * `escalated.agent.chat.end` keeps working.
 */
final class ChatEndTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema();
    }

    public function testTheRouteHasLaravelsName(): void
    {
        $router = static::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        self::assertSame('/support/agent/chat/7/end', $router->generate('escalated.admin.chat.end', ['session' => 7]));
    }

    public function testPostingAnEmptyBodyToTheNamedRouteEndsTheSessionBySessionId(): void
    {
        $agent = $this->agent('agent@example.com');
        // A filler ticket so the session's id and its ticket's id differ.
        $this->ticket();
        $session = $this->chat($agent);
        self::assertNotSame($session->getId(), $session->getTicket()->getId());
        $this->client->loginUser($agent);

        $url = static::getContainer()->get(RouterInterface::class)->generate('escalated.admin.chat.end', ['session' => $session->getId()]);
        $this->client->request('POST', $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'HTTP_X_CSRF_TOKEN' => 'meta-token',
        ], '{}');

        self::assertResponseIsSuccessful();
        self::assertSame(['message' => 'Chat ended.'], $this->json());

        [$fresh, $ticket] = $this->reload($session);
        self::assertSame(ChatSession::STATUS_ENDED, $fresh->getStatus());
        self::assertNotNull($fresh->getEndedAt());
        self::assertNotNull($ticket->getChatEndedAt());
        self::assertSame($agent->getId(), $ticket->getChatMetadata()['ended_by'] ?? null);
    }

    public function testAnAlreadyEndedChatIs422(): void
    {
        $agent = $this->agent('agent@example.com');
        $session = $this->chat($agent, ChatSession::STATUS_ENDED);
        $this->client->loginUser($agent);

        $this->client->jsonRequest('POST', '/support/agent/chat/'.$session->getId().'/end', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => 'Chat is already ended.'], $this->json());
    }

    public function testAnUnknownSessionIs404(): void
    {
        $agent = $this->agent('agent@example.com');
        $this->client->loginUser($agent);

        $this->client->jsonRequest('POST', '/support/agent/chat/999999/end', []);

        self::assertResponseStatusCodeSame(404);
    }

    public function testANonAgentIsRefused(): void
    {
        $agent = $this->agent('agent@example.com');
        $session = $this->chat($agent);
        $customer = new TestUser('customer@example.com');
        $em = static::entityManager();
        $em->persist($customer);
        $em->flush();
        $this->client->loginUser($customer);

        $this->client->jsonRequest('POST', '/support/agent/chat/'.$session->getId().'/end', []);

        self::assertResponseStatusCodeSame(403);
        [$fresh] = $this->reload($session);
        self::assertSame(ChatSession::STATUS_ACTIVE, $fresh->getStatus());
    }

    public function testTheTicketIdEndpointStillWorks(): void
    {
        $agent = $this->agent('agent@example.com');
        $this->ticket();
        $session = $this->chat($agent);
        $ticketId = $session->getTicket()->getId();
        $this->client->loginUser($agent);

        $url = static::getContainer()->get(RouterInterface::class)->generate('escalated.agent.chat.end', ['id' => $ticketId]);
        self::assertSame('/support/agent/chat/sessions/'.$ticketId.'/end', $url);
        $this->client->jsonRequest('POST', $url, []);

        self::assertResponseIsSuccessful();
        self::assertSame(['data' => ['status' => 'ended']], $this->json());
        [$fresh] = $this->reload($session);
        self::assertSame(ChatSession::STATUS_ENDED, $fresh->getStatus());
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

    private function ticket(): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject('Live Chat')
            ->setChannel(Ticket::CHANNEL_CHAT)
            ->setStatus(Ticket::STATUS_LIVE)
            ->setGuestName('Ada')
            ->setGuestToken('chat-token-'.bin2hex(random_bytes(8)))
            ->setReference('ESC-C'.bin2hex(random_bytes(4)));
        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function chat(TestUser $agent, string $status = ChatSession::STATUS_ACTIVE): ChatSession
    {
        $ticket = $this->ticket()->setAssignedTo($agent->getId());
        $session = new ChatSession();
        $session->setTicket($ticket);
        $session->setAgentId($agent->getId());
        $session->setStatus($status);
        $em = static::entityManager();
        $em->persist($session);
        $em->flush();

        return $session;
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
}
