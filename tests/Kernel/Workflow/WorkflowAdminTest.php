<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Workflow;

use Escalated\Symfony\Entity\DelayedAction;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\WorkflowEngine;
use Escalated\Symfony\Tests\Kernel\EscalatedWebTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The Workflows admin, against escalated-developer-context
 * domain-model/workflow-admin-contract.md. The bundle had a workflow engine
 * but no admin routes, so the shared Workflows pages had nothing to render
 * and a workflow could not be built in the UI at all. The engine also
 * ignored add_tag, remove_tag, set_department and insert_canned_reply, so a
 * workflow built with the core action catalog ran and changed nothing.
 *
 * Every request body here is the one the shared builder posts.
 */
final class WorkflowAdminTest extends EscalatedWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::clientWithSchema();

        $admin = new TestUser('admin@example.com', ['ROLE_ESCALATED_ADMIN']);
        $em = static::entityManager();
        $em->persist($admin);
        $em->flush();
        $this->client->loginUser($admin);
    }

    public function testCreateStoresTheContractBodyAsSent(): void
    {
        $department = $this->department();

        $this->inertia('POST', '/support/admin/workflows', $this->contractBody((string) $department->getId()));

        self::assertResponseRedirects('/support/admin/workflows');
        $workflow = $this->onlyWorkflow();
        self::assertSame('Route refunds to billing', $workflow->getName());
        self::assertNull($workflow->getDescription());
        self::assertSame('ticket.created', $workflow->getTriggerEvent());
        self::assertSame(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'refund']]], $workflow->getConditions());
        self::assertSame([
            ['type' => 'change_priority', 'value' => 'high'],
            ['type' => 'set_department', 'value' => (string) $department->getId()],
        ], $workflow->getActions());
        self::assertTrue($workflow->isActive());
    }

    public function testACreatedWorkflowRunsItsActionsWhenItsTriggerFires(): void
    {
        $department = $this->department();
        $this->inertia('POST', '/support/admin/workflows', $this->contractBody((string) $department->getId()));
        self::assertResponseRedirects();

        $ticket = $this->tickets()->create(['subject' => 'Please refund my order']);
        $other = $this->tickets()->create(['subject' => 'Printer on fire']);

        $em = static::entityManager();
        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame('high', $ticket->getPriority());
        self::assertSame($department->getId(), $ticket->getDepartment()?->getId());
        $other = $em->getRepository(Ticket::class)->find($other->getId());
        self::assertSame(Ticket::PRIORITY_MEDIUM, $other->getPriority());
        self::assertNull($other->getDepartment());
    }

    public function testEveryCoreActionChangesTheTicket(): void
    {
        $em = static::entityManager();
        $department = $this->department();
        $stale = (new Tag())->setName('stale')->setSlug('stale');
        $em->persist($stale);
        $em->flush();
        $ticket = $this->tickets()->create(['subject' => 'Refund please']);
        $this->tickets()->addTags($ticket, [(int) $stale->getId()]);

        $this->inertia('POST', '/support/admin/workflows', [
            'name' => 'Every core action',
            'trigger_event' => 'reply.created',
            'conditions' => ['any' => [['field' => 'subject', 'operator' => 'starts_with', 'value' => 'Refund']]],
            'actions' => [
                ['type' => 'change_status', 'value' => 'in_progress'],
                ['type' => 'change_priority', 'value' => 'urgent'],
                ['type' => 'add_tag', 'value' => 'billing'],
                ['type' => 'remove_tag', 'value' => 'stale'],
                ['type' => 'set_department', 'value' => (string) $department->getId()],
                ['type' => 'assign_agent', 'value' => '42'],
                ['type' => 'add_note', 'value' => 'Routed {{reference}}'],
                ['type' => 'insert_canned_reply', 'value' => 'Thanks, we are on {{subject}}.'],
            ],
            'is_active' => true,
        ]);
        self::assertResponseRedirects();

        $this->tickets()->addReply($ticket, 7, 'Any news?');

        $em->clear();
        $ticket = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame('in_progress', $ticket->getStatus());
        self::assertSame('urgent', $ticket->getPriority());
        self::assertSame(['billing'], array_values(array_map(static fn (Tag $t): string => $t->getName(), $ticket->getTags()->toArray())));
        self::assertSame($department->getId(), $ticket->getDepartment()?->getId());
        self::assertSame(42, $ticket->getAssignedTo());

        $replies = $em->getRepository(Reply::class)->findBy(['ticket' => $ticket->getId()], ['id' => 'ASC']);
        $bodies = array_map(static fn (Reply $r): array => [$r->getBody(), $r->isInternalNote()], $replies);
        self::assertContains(['Routed '.$ticket->getReference(), true], $bodies);
        self::assertContains(['Thanks, we are on Refund please.', false], $bodies);
        // The canned reply is not a reply.created of its own: the workflow ran once.
        self::assertCount(3, $replies);
    }

    public function testConditionsOnTheContractFieldsAreEvaluated(): void
    {
        $em = static::entityManager();
        $tag = (new Tag())->setName('vip')->setSlug('vip');
        $em->persist($tag);
        $em->flush();
        $ticket = $this->tickets()->create(['subject' => 'Hello', 'ticket_type' => 'question']);
        $ticket->setChannel('email');
        $em->flush();
        $this->tickets()->addTags($ticket, [(int) $tag->getId()]);

        $engine = static::getContainer()->get(WorkflowEngine::class);
        \assert($engine instanceof WorkflowEngine);

        foreach ([
            ['tags', 'contains', 'vip', true],
            ['tags', 'contains', 'gold', false],
            ['ticket_type', 'equals', 'question', true],
            ['channel', 'equals', 'email', true],
            ['hours_since_created', 'less_than', '1', true],
            ['hours_since_updated', 'greater_or_equal', '1', false],
            ['assigned_to', 'is_empty', '', true],
        ] as [$field, $operator, $value, $expected]) {
            self::assertSame(
                $expected,
                $engine->evaluateConditions(['all' => [['field' => $field, 'operator' => $operator, 'value' => $value]]], $ticket),
                "$field $operator $value",
            );
        }
    }

    public function testAMissingNameOrNoActionsIsRejectedWithErrors(): void
    {
        $body = $this->contractBody('1');
        unset($body['name']);
        $this->client->request('GET', '/support/admin/workflows/create', server: ['HTTP_X_INERTIA' => 'true']);
        $this->inertia('POST', '/support/admin/workflows', $body, ['HTTP_REFERER' => '/support/admin/workflows/create']);
        self::assertResponseRedirects('/support/admin/workflows/create');
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');
        self::assertArrayHasKey('name', $errors[0] ?? []);

        $body = $this->contractBody('1');
        $body['actions'] = [];
        $this->inertia('POST', '/support/admin/workflows', $body);
        self::assertResponseRedirects();
        $errors = $this->client->getRequest()->getSession()->getFlashBag()->peek('errors');
        self::assertArrayHasKey('actions', end($errors) ?: []);

        self::assertSame(0, static::entityManager()->getRepository(Workflow::class)->count([]));
    }

    public function testOmittedConditionsAreStoredAsAnEmptyAllAndMatchEveryTicket(): void
    {
        $body = $this->contractBody('1');
        unset($body['conditions']);
        $body['actions'] = [['type' => 'change_priority', 'value' => 'low']];

        $this->inertia('POST', '/support/admin/workflows', $body);
        self::assertResponseRedirects();
        self::assertSame(['all' => []], $this->onlyWorkflow()->getConditions());

        $ticket = $this->tickets()->create(['subject' => 'Anything']);
        static::entityManager()->clear();
        self::assertSame('low', static::entityManager()->getRepository(Ticket::class)->find($ticket->getId())->getPriority());
    }

    public function testUpdateReplacesTheWorkflow(): void
    {
        $workflow = $this->stored('ticket.created');

        $this->inertia('PUT', '/support/admin/workflows/'.$workflow->getId(), [
            'name' => 'Renamed',
            'description' => 'Now on replies',
            'trigger_event' => 'reply.created',
            'conditions' => ['any' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'high']]],
            'actions' => [['type' => 'add_tag', 'value' => 'escalate']],
            'is_active' => false,
        ]);

        self::assertResponseRedirects('/support/admin/workflows');
        $workflow = $this->onlyWorkflow();
        self::assertSame('Renamed', $workflow->getName());
        self::assertSame('Now on replies', $workflow->getDescription());
        self::assertSame('reply.created', $workflow->getTriggerEvent());
        self::assertSame(['any' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'high']]], $workflow->getConditions());
        self::assertSame([['type' => 'add_tag', 'value' => 'escalate']], $workflow->getActions());
        self::assertFalse($workflow->isActive());
    }

    public function testALegacyTriggerNameIsStoredAsItsCanonicalName(): void
    {
        $body = $this->contractBody('1');
        $body['trigger_event'] = 'ticket.replied';

        $this->inertia('POST', '/support/admin/workflows', $body);

        self::assertSame('reply.created', $this->onlyWorkflow()->getTriggerEvent());
    }

    public function testTheFormPageGetsTheOptionListsTheBackendSupports(): void
    {
        $this->client->request('GET', '/support/admin/workflows/create', server: ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $page = $this->page();
        self::assertSame('Escalated/Admin/Workflows/Form', $page['component']);
        self::assertNull($page['props']['workflow']);

        $triggers = array_column($page['props']['trigger_events'], 'value');
        self::assertSame(EventNames::EMITTED, $triggers);
        foreach ($page['props']['trigger_events'] as $option) {
            self::assertNotSame('', $option['label']);
        }

        $actions = array_column($page['props']['action_types'], 'value');
        foreach (['change_status', 'change_priority', 'add_tag', 'remove_tag', 'set_department', 'assign_agent', 'add_note', 'insert_canned_reply'] as $core) {
            self::assertContains($core, $actions);
        }
        self::assertNotContains('send_webhook', $actions, 'offered an action the engine does not run');

        $operators = array_column($page['props']['operators'], 'value');
        self::assertSame(WorkflowEngine::OPERATORS, $operators);
    }

    public function testTheEditPageGetsTheWorkflowObject(): void
    {
        $workflow = $this->stored('ticket.replied');

        $this->client->request('GET', '/support/admin/workflows/'.$workflow->getId().'/edit', server: ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $page = $this->page();
        self::assertSame('Escalated/Admin/Workflows/Form', $page['component']);
        $object = $page['props']['workflow'];
        self::assertSame($workflow->getId(), $object['id']);
        self::assertSame('Stored', $object['name']);
        self::assertSame('reply.created', $object['trigger_event'], 'a legacy trigger is shown under its canonical name');
        self::assertSame(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'refund']]], $object['conditions']);
        self::assertSame([['type' => 'add_note', 'value' => 'hi']], $object['actions']);
        self::assertTrue($object['is_active']);
        self::assertIsInt($object['position']);
        self::assertArrayHasKey('trigger_events', $page['props']);
    }

    public function testIndexListsWorkflowsByPosition(): void
    {
        $second = $this->stored('ticket.created', 'Second', 2);
        $first = $this->stored('ticket.created', 'First', 1);

        $this->client->request('GET', '/support/admin/workflows', server: ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $page = $this->page();
        self::assertSame('Escalated/Admin/Workflows/Index', $page['component']);
        self::assertSame([$first->getId(), $second->getId()], array_column($page['props']['workflows'], 'id'));
        self::assertSame('ticket.created', $page['props']['workflows'][0]['trigger_event']);
    }

    public function testToggleReorderAndDelete(): void
    {
        $a = $this->stored('ticket.created', 'A', 1);
        $b = $this->stored('ticket.created', 'B', 2);
        $c = $this->stored('ticket.created', 'C', 3);

        $this->inertia('POST', '/support/admin/workflows/'.$a->getId().'/toggle');
        self::assertResponseRedirects('/support/admin/workflows');

        $this->inertia('POST', '/support/admin/workflows/reorder', ['workflow_ids' => [$c->getId(), $a->getId(), $b->getId()]]);
        self::assertResponseRedirects('/support/admin/workflows');

        $this->inertia('DELETE', '/support/admin/workflows/'.$b->getId());
        self::assertResponseRedirects('/support/admin/workflows');

        $em = static::entityManager();
        $em->clear();
        $repo = $em->getRepository(Workflow::class);
        self::assertNull($repo->find($b->getId()));
        self::assertFalse($repo->find($a->getId())->isActive());
        self::assertTrue($repo->find($c->getId())->isActive());
        self::assertSame(['C', 'A'], array_map(static fn (Workflow $w): string => $w->getName(), $repo->findBy([], ['position' => 'ASC'])));
    }

    public function testTheLogsPageListsExecutions(): void
    {
        $this->inertia('POST', '/support/admin/workflows', $this->contractBody((string) $this->department()->getId()));
        $ticket = $this->tickets()->create(['subject' => 'refund now']);

        $this->client->request('GET', '/support/admin/workflows/logs', server: ['HTTP_X_INERTIA' => 'true']);

        self::assertResponseIsSuccessful();
        $page = $this->page();
        self::assertSame('Escalated/Admin/Workflows/Logs', $page['component']);
        self::assertSame([['id' => $this->onlyWorkflow()->getId(), 'name' => 'Route refunds to billing']], $page['props']['workflows']);
        $log = $page['props']['logs'][0];
        self::assertSame('Route refunds to billing', $log['workflow_name']);
        self::assertSame($ticket->getReference(), $log['ticket_reference']);
        self::assertSame('ticket.created', $log['event']);
        self::assertTrue($log['matched']);
        self::assertSame(2, $log['actions_executed']);
        self::assertSame('success', $log['status']);
        self::assertSame(['change_priority', 'set_department'], array_column($log['action_details'], 'action_type'));
        self::assertSame(['success', 'success'], array_column($log['action_details'], 'status'));
    }

    public function testADelayDefersTheRemainingActions(): void
    {
        $this->inertia('POST', '/support/admin/workflows', [
            'name' => 'Later',
            'trigger_event' => 'reply.created',
            'conditions' => ['all' => []],
            'actions' => [
                ['type' => 'add_tag', 'value' => 'now'],
                ['type' => 'delay', 'value' => '30'],
                ['type' => 'change_priority', 'value' => 'low'],
            ],
            'is_active' => true,
        ]);
        $ticket = $this->tickets()->create(['subject' => 'Wait for it']);

        $this->tickets()->addReply($ticket, 1, 'ping');

        $em = static::entityManager();
        $em->clear();
        $fresh = $em->getRepository(Ticket::class)->find($ticket->getId());
        self::assertSame(Ticket::PRIORITY_MEDIUM, $fresh->getPriority(), 'the action after the delay ran at once');
        self::assertCount(1, $fresh->getTags());
        $delayed = $em->getRepository(DelayedAction::class)->findAll();
        self::assertCount(1, $delayed);
        self::assertGreaterThan(new \DateTimeImmutable('+29 minutes'), $delayed[0]->getExecuteAt());

        $delayed[0]->setExecuteAt(new \DateTimeImmutable('-1 minute'));
        $em->flush();
        $engine = static::getContainer()->get(WorkflowEngine::class);
        \assert($engine instanceof WorkflowEngine);
        $engine->processDelayedActions();

        $em->clear();
        self::assertSame('low', $em->getRepository(Ticket::class)->find($ticket->getId())->getPriority());
    }

    public function testAgentsWhoAreNotAdminsAreRefused(): void
    {
        $agent = new TestUser('agent@example.com');
        $em = static::entityManager();
        $em->persist($agent);
        $em->flush();
        $this->client->loginUser($agent);

        $this->client->request('GET', '/support/admin/workflows', server: ['HTTP_X_INERTIA' => 'true']);
        self::assertResponseStatusCodeSame(403);
        $this->inertia('POST', '/support/admin/workflows', $this->contractBody('1'));
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, mixed> */
    private function contractBody(string $departmentId): array
    {
        return [
            'name' => 'Route refunds to billing',
            'description' => null,
            'trigger_event' => 'ticket.created',
            'conditions' => ['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'refund']]],
            'actions' => [
                ['type' => 'change_priority', 'value' => 'high'],
                ['type' => 'set_department', 'value' => $departmentId],
            ],
            'is_active' => true,
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $server
     */
    private function inertia(string $method, string $uri, ?array $body = null, array $server = []): void
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ...$server,
        ], content: null === $body ? null : (string) json_encode($body));
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        $page = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($page, 'expected an Inertia page');

        return $page;
    }

    private function onlyWorkflow(): Workflow
    {
        $em = static::entityManager();
        $em->clear();
        $all = $em->getRepository(Workflow::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function stored(string $trigger, string $name = 'Stored', int $position = 0): Workflow
    {
        $workflow = (new Workflow())
            ->setName($name)
            ->setTriggerEvent($trigger)
            ->setPosition($position)
            ->setConditions(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'refund']]])
            ->setActions([['type' => 'add_note', 'value' => 'hi']]);
        $em = static::entityManager();
        $em->persist($workflow);
        $em->flush();

        return $workflow;
    }

    private function department(): Department
    {
        $department = (new Department())->setName('Billing')->setSlug('billing-'.bin2hex(random_bytes(3)));
        $em = static::entityManager();
        $em->persist($department);
        $em->flush();

        return $department;
    }

    private function tickets(): TicketService
    {
        $service = static::getContainer()->get(TicketService::class);
        \assert($service instanceof TicketService);

        return $service;
    }
}
