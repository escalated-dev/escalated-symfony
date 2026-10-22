<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Statuses;

use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketStatus;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Service\ApiTokenService;
use Escalated\Symfony\Service\MacroService;
use Escalated\Symfony\Service\SlaService;
use Escalated\Symfony\Service\SnoozeService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\WorkflowEngine;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Custom statuses are sub-statuses of a category: setting one moves the
 * ticket's built-in status to the category's anchor through the normal
 * status-change path, so everything keyed off the built-in status (resolved_at,
 * SLA, events) follows. Setting a built-in status clears the custom one.
 */
final class CustomStatusLifecycleTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;
    private TestUser $agent;
    private TestUser $customer;

    /** @var list<TicketWorkflowEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema(['escalated' => ['user_class' => TestUser::class]]);
        $em = static::entityManager();
        $this->agent = new TestUser('agent@example.com');
        $this->customer = new TestUser('customer@example.com');
        $em->persist($this->agent);
        $em->persist($this->customer);
        $em->flush();
        $em->persist((new AgentProfile())->setUserId((int) $this->agent->getId()));
        $em->flush();

        $dispatcher = static::getContainer()->get('event_dispatcher');
        \assert($dispatcher instanceof EventDispatcherInterface);
        $dispatcher->addListener(TicketWorkflowEvent::class, function (TicketWorkflowEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testASolvedCustomStatusResolvesTheTicketAndStopsItsSla(): void
    {
        $fixed = $this->makeStatus('Fixed, awaiting confirmation', 'fixed_awaiting', 'solved');
        $ticket = $this->ticket();
        $ticket->setResolutionDueAt(new \DateTimeImmutable('-1 hour'));
        static::entityManager()->flush();

        $this->tickets()->changeStatus($ticket, 'fixed_awaiting');

        self::assertSame(Ticket::STATUS_RESOLVED, $ticket->getStatus());
        self::assertSame($fixed, $ticket->getCustomStatus());
        self::assertNotNull($ticket->getResolvedAt());

        $sla = static::getContainer()->get(SlaService::class);
        \assert($sla instanceof SlaService);
        $sla->checkBreaches();
        static::entityManager()->refresh($ticket);
        self::assertFalse($ticket->isSlaResolutionBreached());

        $changed = $this->eventsNamed('ticket.status_changed');
        self::assertCount(1, $changed);
        self::assertSame(['old_status' => 'open', 'new_status' => 'resolved', 'old_custom_status' => null, 'new_custom_status' => 'fixed_awaiting', 'old_category' => 'open', 'new_category' => 'solved'], array_diff_key($changed[0]->context, ['causer_id' => true]));
    }

    public function testAPendingCustomStatusThroughTheAgentEndpointWaitsOnTheCustomer(): void
    {
        $this->makeStatus('Awaiting vendor', 'awaiting_vendor', 'pending', '#abcdef');
        $ticket = $this->ticket();
        $this->client->loginUser($this->agent);

        $this->post('/support/agent/tickets/'.$ticket->getReference().'/status', ['status' => 'awaiting_vendor']);
        self::assertResponseRedirects();

        $em = static::entityManager();
        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame(Ticket::STATUS_WAITING_ON_CUSTOMER, $ticket->getStatus());
        self::assertSame('awaiting_vendor', $ticket->getCustomStatus()?->getSlug());

        $page = $this->visit('/support/agent/tickets/'.$ticket->getReference());
        $props = $page['props']['ticket'];
        self::assertSame('waiting_on_customer', $props['status']);
        self::assertSame('pending', $props['status_category']);
        self::assertSame(['id' => $ticket->getCustomStatus()->getId(), 'label' => 'Awaiting vendor', 'slug' => 'awaiting_vendor', 'color' => '#abcdef', 'category' => 'pending'], $props['custom_status']);

        $queue = $this->visit('/support/agent/tickets');
        $row = $queue['props']['tickets']['data'][0];
        self::assertSame('awaiting_vendor', $row['custom_status']['slug']);
        self::assertSame('pending', $row['status_category']);
    }

    public function testATicketWithoutACustomStatusReportsItsBuiltInCategory(): void
    {
        $ticket = $this->ticket();
        $this->tickets()->changeStatus($ticket, Ticket::STATUS_WAITING_ON_CUSTOMER);
        $this->client->loginUser($this->agent);

        $props = $this->visit('/support/agent/tickets/'.$ticket->getReference())['props']['ticket'];
        self::assertNull($props['custom_status']);
        self::assertSame('pending', $props['status_category']);
    }

    public function testSettingABuiltInStatusClearsTheCustomStatus(): void
    {
        $this->makeStatus('Awaiting vendor', 'awaiting_vendor', 'pending');
        $ticket = $this->ticket();
        $this->tickets()->changeStatus($ticket, 'awaiting_vendor');

        $this->tickets()->changeStatus($ticket, Ticket::STATUS_IN_PROGRESS);
        self::assertNull($ticket->getCustomStatus());
        static::entityManager()->clear();
        self::assertNull(static::entityManager()->getRepository(Ticket::class)->find($ticket->getId())->getCustomStatus());
    }

    public function testChoosingTheAnchorBuiltInAgainClearsTheCustomStatus(): void
    {
        $this->makeStatus('Awaiting vendor', 'awaiting_vendor', 'pending');
        $ticket = $this->ticket();
        $this->tickets()->changeStatus($ticket, 'awaiting_vendor');

        $this->tickets()->changeStatus($ticket, Ticket::STATUS_WAITING_ON_CUSTOMER);
        self::assertNull($ticket->getCustomStatus());
        self::assertSame(Ticket::STATUS_WAITING_ON_CUSTOMER, $ticket->getStatus());
    }

    public function testSnoozingClearsTheCustomStatus(): void
    {
        $this->makeStatus('Awaiting vendor', 'awaiting_vendor', 'pending');
        $ticket = $this->ticket();
        $this->tickets()->changeStatus($ticket, 'awaiting_vendor');

        $snooze = static::getContainer()->get(SnoozeService::class);
        \assert($snooze instanceof SnoozeService);
        $snooze->snooze($ticket, new \DateTimeImmutable('+1 day'), (int) $this->agent->getId());

        self::assertSame(Ticket::STATUS_SNOOZED, $ticket->getStatus());
        self::assertNull($ticket->getCustomStatus());
    }

    public function testASameCategoryMoveStillPersistsAndAnnouncesTheChange(): void
    {
        $this->makeStatus('Awaiting vendor', 'awaiting_vendor', 'pending');
        $parts = $this->makeStatus('Awaiting parts', 'awaiting_parts', 'pending');
        $ticket = $this->ticket();
        $this->tickets()->changeStatus($ticket, 'awaiting_vendor');
        $this->events = [];

        $this->tickets()->changeStatus($ticket, 'awaiting_parts');

        static::entityManager()->clear();
        $ticket = static::entityManager()->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame(Ticket::STATUS_WAITING_ON_CUSTOMER, $ticket->getStatus());
        self::assertSame($parts->getId(), $ticket->getCustomStatus()?->getId());

        $changed = $this->eventsNamed('ticket.status_changed');
        self::assertCount(1, $changed);
        self::assertSame('waiting_on_customer', $changed[0]->context['old_status']);
        self::assertSame('waiting_on_customer', $changed[0]->context['new_status']);
        self::assertSame('awaiting_vendor', $changed[0]->context['old_custom_status']);
        self::assertSame('awaiting_parts', $changed[0]->context['new_custom_status']);
    }

    public function testASameCategoryMoveWithinSolvedKeepsResolvedAt(): void
    {
        $this->makeStatus('Fixed', 'fixed', 'solved');
        $this->makeStatus('Wont fix', 'wont_fix', 'solved');
        $ticket = $this->ticket();
        $this->tickets()->changeStatus($ticket, 'fixed');
        $resolvedAt = new \DateTimeImmutable('-3 days');
        $ticket->setResolvedAt($resolvedAt);
        static::entityManager()->flush();

        $this->events = [];
        $this->tickets()->changeStatus($ticket, 'wont_fix');

        self::assertEquals($resolvedAt, $ticket->getResolvedAt());
        // A status change, but not a second ticket.resolved.
        $changed = $this->eventsNamed('ticket.status_changed');
        self::assertCount(1, $changed);
        self::assertSame(['ticket.status_changed'], EventNames::forEvent($changed[0]));
        $first = new TicketWorkflowEvent('ticket.status_changed', $ticket, ['old_status' => 'open', 'new_status' => 'resolved']);
        self::assertSame(['ticket.status_changed', 'ticket.resolved'], EventNames::forEvent($first));
    }

    public function testUnknownStatusesAreStillRejected(): void
    {
        $ticket = $this->ticket();
        $this->client->loginUser($this->agent);

        $this->post('/support/agent/tickets/'.$ticket->getReference().'/status', ['status' => 'nonsense'], ['HTTP_REFERER' => '/support/agent/tickets/'.$ticket->getReference()]);
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');
        self::assertArrayHasKey('status', end($errors) ?: []);

        $this->expectException(\InvalidArgumentException::class);
        $this->tickets()->changeStatus($ticket, 'nonsense');
    }

    public function testTheApiAndBulkActionAcceptACustomSlug(): void
    {
        $this->makeStatus('Awaiting vendor', 'awaiting_vendor', 'pending');
        $this->makeStatus('On ice', 'on_ice', 'on_hold');
        $a = $this->ticket();
        $b = $this->ticket();

        $tokens = static::getContainer()->get(ApiTokenService::class);
        \assert($tokens instanceof ApiTokenService);
        $token = $tokens->createToken((int) $this->agent->getId(), 'test', ['agent'])['plainTextToken'];
        $this->client->jsonRequest('POST', '/support/api/v1/tickets/'.$a->getReference().'/status', ['status' => 'awaiting_vendor'], ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        self::assertSame('awaiting_vendor', $data['custom_status']['slug']);
        self::assertSame('pending', $data['status_category']);

        static::entityManager()->clear();
        self::assertSame(Ticket::STATUS_WAITING_ON_CUSTOMER, static::entityManager()->getRepository(Ticket::class)->find($a->getId())->getStatus());

        $this->client->loginUser($this->agent);
        $this->post('/support/agent/tickets/bulk', ['ticket_ids' => [$b->getId()], 'action' => 'status', 'value' => 'on_ice'], ['HTTP_REFERER' => '/support/agent/tickets']);
        static::entityManager()->clear();
        $b = static::entityManager()->getRepository(Ticket::class)->find($b->getId());
        self::assertSame(Ticket::STATUS_IN_PROGRESS, $b->getStatus());
        self::assertSame('on_ice', $b->getCustomStatus()?->getSlug());
    }

    public function testWorkflowAndMacroStatusActionsResolveCustomSlugs(): void
    {
        $this->makeStatus('Awaiting vendor', 'awaiting_vendor', 'pending');
        $this->makeStatus('Fixed', 'fixed', 'solved');
        $em = static::entityManager();

        $ticket = $this->ticket();
        $em->persist((new Workflow())->setName('Wait')->setTriggerEvent('ticket.updated')->setPosition(1)
            ->setConditions(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'Printer']]])
            ->setActions([['type' => 'change_status', 'value' => 'awaiting_vendor']]));
        $em->flush();
        $engine = static::getContainer()->get(WorkflowEngine::class);
        \assert($engine instanceof WorkflowEngine);
        $engine->processEvent('ticket.updated', $ticket);
        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame(Ticket::STATUS_WAITING_ON_CUSTOMER, $ticket->getStatus());
        self::assertSame('awaiting_vendor', $ticket->getCustomStatus()?->getSlug());

        $macro = (new Macro())->setName('Fix')->setActions([['type' => 'set_status', 'value' => 'fixed']])->setIsShared(true);
        $em->persist($macro);
        $em->flush();
        $macros = static::getContainer()->get(MacroService::class);
        \assert($macros instanceof MacroService);
        $macros->apply($macro, $ticket, (int) $this->agent->getId());
        self::assertSame(Ticket::STATUS_RESOLVED, $ticket->getStatus());
        self::assertSame('fixed', $ticket->getCustomStatus()?->getSlug());

        $macro2 = (new Macro())->setName('Close')->setActions([['type' => 'set_status', 'value' => 'closed']])->setIsShared(true);
        $em->persist($macro2);
        $em->flush();
        $macros->apply($macro2, $ticket, (int) $this->agent->getId());
        self::assertSame(Ticket::STATUS_CLOSED, $ticket->getStatus());
        self::assertNull($ticket->getCustomStatus());
    }

    public function testTheCustomerViewShowsOnlyTheBuiltInStatus(): void
    {
        $this->makeStatus('Escalated to vendor XYZ', 'vendor_xyz', 'pending');
        $ticket = $this->tickets()->create(['subject' => 'Mine', 'description' => 'Help', 'requester_id' => $this->customer->getId(), 'requester_class' => TestUser::class]);
        $this->tickets()->changeStatus($ticket, 'vendor_xyz');
        $this->client->loginUser($this->customer);

        foreach (['/support/customer/tickets/'.$ticket->getReference(), '/support/customer/tickets'] as $uri) {
            $this->client->request('GET', $uri, server: ['HTTP_X_INERTIA' => 'true', 'HTTP_ACCEPT' => 'text/html, application/xhtml+xml']);
            self::assertResponseIsSuccessful();
            $body = (string) $this->client->getResponse()->getContent();
            self::assertStringContainsString('waiting_on_customer', $body);
            self::assertStringNotContainsString('vendor_xyz', $body);
            self::assertStringNotContainsString('Escalated to vendor XYZ', $body);
            self::assertStringNotContainsString('custom_status', $body);
        }
    }

    private function makeStatus(string $label, string $slug, string $category, string $color = '#6b7280'): TicketStatus
    {
        $status = (new TicketStatus())->setLabel($label)->setSlug($slug)->setCategory($category)->setColor($color);
        $em = static::entityManager();
        $em->persist($status);
        $em->flush();

        return $status;
    }

    private function ticket(): Ticket
    {
        return $this->tickets()->create(['subject' => 'Printer on fire', 'description' => 'Smoke']);
    }

    private function tickets(): TicketService
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);

        return $service;
    }

    /**
     * @return list<TicketWorkflowEvent>
     */
    private function eventsNamed(string $name): array
    {
        return array_values(array_filter($this->events, static fn (TicketWorkflowEvent $e): bool => $e->triggerName === $name));
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
}
