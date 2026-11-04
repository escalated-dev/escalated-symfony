<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Ui;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\DepartmentAgent;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;
use Escalated\Symfony\Security\RoutePermissions;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * What the agent ticket page needs beyond the ticket itself (escalated-laravel
 * #289): the agents it can be reassigned to, the requester's other tickets,
 * and snoozing from the agent panel.
 */
final class AgentWorkspaceTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $admin;
    private TestUser $agent;
    private TestUser $other;
    private TestUser $customer;
    private int $seq = 0;

    /**
     * @param array<string, mixed> $config
     */
    private function boot(array $config = []): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class, ...$config]]);
        $em = static::entityManager();

        $this->admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $this->agent = new TestUser('agent@example.com');
        $this->other = new TestUser('otto@example.com');
        $this->customer = new TestUser('cass@example.com');
        foreach ([$this->admin, $this->agent, $this->other, $this->customer] as $user) {
            $em->persist($user);
        }
        $em->flush();
        foreach ([$this->admin, $this->agent, $this->other] as $user) {
            $em->persist((new AgentProfile())->setUserId((int) $user->getId()));
        }
        $em->flush();
    }

    // --- Reassigning ------------------------------------------------------------

    public function testSendsTheAgentsAndAdminsATicketCanBeAssignedToByIdAndName(): void
    {
        $this->boot();
        $ticket = $this->ticket();

        $agents = $this->show($this->agent, $ticket)['agents'];

        self::assertSame([
            ['id' => $this->admin->getId(), 'name' => 'admin@example.com'],
            ['id' => $this->agent->getId(), 'name' => 'agent@example.com'],
            ['id' => $this->other->getId(), 'name' => 'otto@example.com'],
        ], $agents);
    }

    public function testLeavesOutAgentsWhoCannotSeeTheTicketUnderDepartmentVisibility(): void
    {
        $this->boot(['visibility' => 'department']);
        $department = $this->department('Support');
        $ticket = $this->ticket(['department' => $department]);

        $names = array_column($this->show($this->agent, $ticket)['agents'], 'name');

        self::assertSame(['admin@example.com', 'agent@example.com'], $names);
    }

    // --- The requester's other tickets ------------------------------------------

    public function testSendsTheRequestersOtherTicketsNewestFirstAtMostFive(): void
    {
        $this->boot();
        $older = [];
        foreach (range(1, 6) as $i) {
            $older[$i] = $this->ticket(['subject' => "Earlier {$i}", 'created_at' => sprintf('-%d days', 10 - $i)]);
        }
        $this->ticket(['subject' => 'Someone else', 'requester' => $this->other]);
        $ticket = $this->ticket();

        $recent = $this->show($this->agent, $ticket)['ticket']['requester_tickets'];

        self::assertSame(['Earlier 6', 'Earlier 5', 'Earlier 4', 'Earlier 3', 'Earlier 2'], array_column($recent, 'subject'));
        self::assertSame(['id', 'reference', 'subject', 'status', 'created_at', 'updated_at'], array_keys($recent[0]));
        self::assertSame($older[6]->getId(), $recent[0]['id']);
        self::assertSame($older[6]->getReference(), $recent[0]['reference']);
        self::assertSame('open', $recent[0]['status']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $recent[0]['created_at']));
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $recent[0]['updated_at']));
    }

    public function testBreaksCreatedAtTiesByTheNewestId(): void
    {
        $this->boot();
        $first = $this->ticket(['subject' => 'First', 'created_at' => '2026-01-01 10:00:00']);
        $second = $this->ticket(['subject' => 'Second', 'created_at' => '2026-01-01 10:00:00']);
        $ticket = $this->ticket();

        $recent = $this->show($this->agent, $ticket)['ticket']['requester_tickets'];

        self::assertSame([$second->getId(), $first->getId()], array_column($recent, 'id'));
    }

    public function testMatchesAGuestRequestersOtherTicketsByEmail(): void
    {
        $this->boot();
        $this->guestTicket('guest@example.com', 'Earlier guest ticket');
        $this->guestTicket('other@example.com', 'Another guest');
        $ticket = $this->guestTicket('guest@example.com', 'Current');

        $recent = $this->show($this->agent, $ticket)['ticket']['requester_tickets'];

        self::assertSame(['Earlier guest ticket'], array_column($recent, 'subject'));
    }

    public function testOnlyListsRequesterTicketsTheAgentMaySee(): void
    {
        $this->boot(['visibility' => 'department']);
        $support = $this->department('Support');
        $hidden = $this->department('Hidden', false);
        $this->ticket(['subject' => 'Visible', 'department' => $support]);
        $this->ticket(['subject' => 'Hidden', 'department' => $hidden]);
        $ticket = $this->ticket(['department' => $support]);

        $recent = $this->show($this->agent, $ticket)['ticket']['requester_tickets'];

        self::assertSame(['Visible'], array_column($recent, 'subject'));
    }

    public function testSendsAnEmptyListForATicketWithNoKnownRequester(): void
    {
        $this->boot();
        $this->ticket(['requester' => null]);
        $ticket = $this->ticket(['requester' => null]);

        self::assertSame([], $this->show($this->agent, $ticket)['ticket']['requester_tickets']);
    }

    public function testKeepsRelatedTicketsAsTheyWere(): void
    {
        $this->boot();
        $this->ticket(['subject' => 'Earlier']);
        $ticket = $this->ticket();

        $detail = $this->show($this->agent, $ticket)['ticket'];

        self::assertSame(['reference', 'subject', 'status'], array_keys($detail['related_tickets'][0]));
        self::assertSame(2, $detail['requester_ticket_count']);
    }

    // --- Snoozing from the agent panel --------------------------------------------

    public function testLetsAnAgentSnoozeATicket(): void
    {
        $this->boot();
        $ticket = $this->ticket();
        $until = new \DateTimeImmutable('+1 day 00:00');
        $base = '/support/agent/tickets/'.$ticket->getReference();

        $this->client->loginUser($this->agent);
        $this->post($base.'/snooze', ['snoozed_until' => $until->format(\DateTimeInterface::ATOM)], ['HTTP_REFERER' => $base]);

        self::assertResponseRedirects($base);
        self::assertNotEmpty($this->client->getRequest()->getSession()->getFlashBag()->peek('success'));
        $fresh = $this->fresh($ticket);
        self::assertEquals($until, $fresh->getSnoozedUntil());
        self::assertSame((string) $this->agent->getId(), (string) $fresh->getSnoozedBy());
        self::assertSame('open', $fresh->getStatusBeforeSnooze());
        $types = array_map(static fn (TicketActivity $a): string => $a->getType(), $fresh->getActivities()->toArray());
        self::assertContains('snoozed', $types);
    }

    public function testRejectsASnoozeTimeInThePast(): void
    {
        $this->boot();
        $ticket = $this->ticket();

        $this->client->loginUser($this->agent);
        $this->client->jsonRequest('POST', '/support/agent/tickets/'.$ticket->getReference().'/snooze', [
            'snoozed_until' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertArrayHasKey('snoozed_until', json_decode((string) $this->client->getResponse()->getContent(), true)['errors']);
        self::assertNull($this->fresh($ticket)->getSnoozedUntil());
    }

    public function testLetsAnAgentUnsnoozeATicketAndRestoresItsStatus(): void
    {
        $this->boot();
        $ticket = $this->ticket(['status' => 'in_progress']);
        $base = '/support/agent/tickets/'.$ticket->getReference();
        $this->client->loginUser($this->agent);
        $this->post($base.'/snooze', ['snoozed_until' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM)]);
        self::assertNotNull($this->fresh($ticket)->getSnoozedUntil());

        $this->post($base.'/unsnooze', [], ['HTTP_REFERER' => $base]);

        self::assertResponseRedirects($base);
        $fresh = $this->fresh($ticket);
        self::assertNull($fresh->getSnoozedUntil());
        self::assertSame('in_progress', $fresh->getStatus());
    }

    public function testDoesNotLetACustomerSnoozeThroughTheAgentPanel(): void
    {
        $this->boot();
        $ticket = $this->ticket();

        $this->client->loginUser($this->customer);
        $this->post('/support/agent/tickets/'.$ticket->getReference().'/snooze', ['snoozed_until' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM)]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->fresh($ticket)->getSnoozedUntil());
    }

    public function testDoesNotLetAnAgentSnoozeATicketOutsideTheirDepartments(): void
    {
        $this->boot(['visibility' => 'department']);
        $ticket = $this->ticket(['department' => $this->department('Hidden', false)]);

        $this->client->loginUser($this->agent);
        $this->post('/support/agent/tickets/'.$ticket->getReference().'/snooze', ['snoozed_until' => (new \DateTimeImmutable('+1 day'))->format(\DateTimeInterface::ATOM)]);

        self::assertResponseStatusCodeSame(404);
        $em = static::entityManager();
        self::assertNull($em->getConnection()->fetchOne(
            'SELECT snoozed_until FROM '.$em->getClassMetadata(Ticket::class)->getTableName().' WHERE id = ?',
            [$ticket->getId()],
        ));
    }

    public function testMapsTheAgentSnoozeRoutesToTheTicketEditPermission(): void
    {
        self::assertTrue(RoutePermissions::isMapped('escalated.agent.tickets.snooze'));
        self::assertTrue(RoutePermissions::isMapped('escalated.agent.tickets.unsnooze'));
        self::assertSame(['ticket.edit'], RoutePermissions::requiredFor('escalated.agent.tickets.snooze'));
        self::assertSame(['ticket.edit'], RoutePermissions::requiredFor('escalated.agent.tickets.unsnooze'));
    }

    public function testDeclaresTheAgentsPropInTheVendoredManifest(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/escalated-pages.json'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertContains('agents', $manifest['props']['Escalated/Agent/TicketShow']['props']);
    }

    // --- Helpers -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function show(TestUser $user, Ticket $ticket): array
    {
        $this->client->loginUser($user);
        $this->client->request('GET', '/support/agent/tickets/'.$ticket->getReference(), server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page);
        self::assertSame('Escalated/Agent/TicketShow', $page['component']);

        return $page['props'];
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     */
    private function post(string $uri, array $body, array $server = []): void
    {
        $this->client->request('POST', $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
            ...$server,
        ], content: (string) json_encode($body));
    }

    /** A department; the agent is a member unless told otherwise. */
    private function department(string $name, bool $withAgent = true): Department
    {
        $em = static::entityManager();
        $department = (new Department())->setName($name)->setSlug(strtolower($name));
        $em->persist($department);
        $em->flush();
        if ($withAgent) {
            $em->persist(new DepartmentAgent($department, $this->agent->getId()));
            $em->flush();
        }

        return $department;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function ticket(array $attributes = []): Ticket
    {
        ++$this->seq;
        $requester = \array_key_exists('requester', $attributes) ? $attributes['requester'] : $this->customer;
        $ticket = (new Ticket())
            ->setSubject($attributes['subject'] ?? 'Workspace '.$this->seq)
            ->setDescription('Details')
            ->setRequesterId(null === $requester ? null : (int) $requester->getId())
            ->setRequesterClass(null === $requester ? null : TestUser::class)
            ->setStatus($attributes['status'] ?? 'open')
            ->setDepartment($attributes['department'] ?? null);

        return $this->persist($ticket, $attributes['created_at'] ?? null);
    }

    private function guestTicket(string $email, string $subject): Ticket
    {
        ++$this->seq;
        $ticket = (new Ticket())
            ->setSubject($subject)
            ->setDescription('Details')
            ->setGuestName('Gus')
            ->setGuestEmail($email)
            ->setStatus('open');

        return $this->persist($ticket, sprintf('-%d minutes', 100 - $this->seq));
    }

    private function persist(Ticket $ticket, ?string $createdAt): Ticket
    {
        $em = static::entityManager();
        $em->persist($ticket);
        $em->flush();
        $ticket->setReference($ticket->generateReference('WS'));
        $em->flush();
        if (null !== $createdAt) {
            $em->getConnection()->update(
                $em->getClassMetadata(Ticket::class)->getTableName(),
                ['created_at' => (new \DateTimeImmutable($createdAt))->format('Y-m-d H:i:s')],
                ['id' => $ticket->getId()],
            );
        }

        return $ticket;
    }

    private function fresh(Ticket $ticket): Ticket
    {
        $em = static::entityManager();
        $em->clear();
        $fresh = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertInstanceOf(Ticket::class, $fresh);

        return $fresh;
    }
}
