<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentNotification;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @mentions, as escalated-laravel's MentionService and AgentSearchController:
 * agents mentioned in an agent's reply or internal note get a `mention`
 * notification, and the composer's picker searches agents at
 * `admin/agents/search`.
 */
final class AgentMentionsTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $agent;
    private TestUser $alice;
    private TestUser $bob;
    private TestUser $customer;
    private TestUser $admin;

    private function boot(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $em = static::entityManager();

        $this->agent = new TestUser('agent@example.com');
        $this->alice = new TestUser('alice@example.com');
        $this->bob = new TestUser('bob.smith@example.com');
        $this->customer = new TestUser('customer@example.com');
        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        foreach ([$this->agent, $this->alice, $this->bob, $this->customer, $this->admin] as $user) {
            $em->persist($user);
        }
        $em->flush();
        foreach ([$this->agent, $this->alice, $this->bob, $this->admin] as $user) {
            $em->persist((new AgentProfile())->setUserId((int) $user->getId()));
        }
        $em->flush();
    }

    public function testAgentsMentionedInANoteAreNotified(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        $this->tickets()->addReply($ticket, (int) $this->agent->getId(), 'Ping @{alice@example.com} and @{BOB.SMITH@example.com}', true, TestUser::class);

        self::assertSame(['mention'], $this->types($this->alice));
        self::assertSame(['mention'], $this->types($this->bob));
        $this->client->loginUser($this->alice);
        $item = $this->json('/support/agent/notifications')['data'][0];
        self::assertSame('agent@example.com mentioned you on '.$ticket->getReference(), $item['title']);
        self::assertSame($ticket->getId(), $item['ticket']['id']);
    }

    public function testAMentionInAnAgentReplyNotifies(): void
    {
        $this->boot();
        $this->tickets()->addReply($this->ticket(), (int) $this->agent->getId(), 'cc @{alice@example.com}, thanks', false, TestUser::class);

        self::assertSame(['mention'], $this->types($this->alice));
    }

    public function testNobodyIsNotifiedOfTheirOwnMentionCustomersOrUnknownNames(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        $this->tickets()->addReply($ticket, (int) $this->agent->getId(), '@{agent@example.com} @{customer@example.com} @nobody', true, TestUser::class);
        $this->tickets()->addReply($this->fresh($ticket), (int) $this->customer->getId(), 'Hi @{alice@example.com}', false, TestUser::class);

        self::assertSame(0, static::entityManager()->getRepository(AgentNotification::class)->count([]));
    }

    public function testTheMentionPickerListsAgentsByNameOrEmail(): void
    {
        $this->boot();
        $this->client->loginUser($this->admin);

        self::assertSame(
            [['id' => $this->bob->getId(), 'name' => 'bob.smith@example.com', 'email' => 'bob.smith@example.com']],
            $this->json('/support/admin/agents/search?q=BOB'),
        );
        self::assertSame([], $this->json('/support/admin/agents/search?q=customer'));
        self::assertSame([], $this->json('/support/admin/agents/search?q='));
    }

    public function testTheMentionPickerIsForAdminsAsInLaravel(): void
    {
        $this->boot();
        $this->client->loginUser($this->agent);
        $this->json('/support/admin/agents/search?q=bob');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    /** @return list<string> */
    private function types(TestUser $user): array
    {
        $rows = static::entityManager()->getRepository(AgentNotification::class)->findBy(['userId' => $user->getId()]);

        return array_map(static fn (AgentNotification $row): string => $row->getType(), $rows);
    }

    private function ticket(): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject('Mentions')
            ->setDescription('Details')
            ->setStatus('open')
            ->setPriority('low')
            ->setRequesterId((int) $this->customer->getId())
            ->setRequesterClass(TestUser::class);
        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();
        $ticket->setReference($ticket->generateReference('MNT'));
        $em->flush();

        return $ticket;
    }

    private function fresh(Ticket $ticket): Ticket
    {
        $found = static::entityManager()->find(Ticket::class, $ticket->getId());
        \assert($found instanceof Ticket);

        return $found;
    }

    private function tickets(): TicketService
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);

        return $service;
    }

    private function json(string $uri): array
    {
        $this->client->request('GET', $uri, server: ['HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
