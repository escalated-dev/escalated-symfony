<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Access;

use Escalated\Symfony\Entity\Automation;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\EscalationRule;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Service\AutomationRunner;
use Escalated\Symfony\Service\EscalationService;
use Escalated\Symfony\Service\SlaService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\TicketSubjectService;
use Escalated\Symfony\Service\WorkflowEngine;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;
use Escalated\Symfony\Tests\Kernel\Fixtures\TestAccountResolver;
use PHPUnit\Framework\Attributes\DataProvider;

final class TicketRoutingTest extends EscalatedKernelTestCase
{
    public static function engines(): iterable
    {
        foreach (['workflow', 'automation', 'escalation'] as $engine) {
            yield $engine => [$engine, ['type' => 'Company', 'id' => 'A'], 1];
            yield $engine.' incomplete account' => [$engine, ['id' => 'A'], 0];
            yield $engine.' wrong case' => [$engine, ['type' => 'company', 'id' => 'A'], 0];
        }
    }

    #[DataProvider('engines')]
    public function testActualEnginesMatchTypeAndCompleteAccount(string $engine, array $account, int $matched): void
    {
        self::bootKernel();
        self::createSchema();
        $em = self::entityManager();
        $tickets = [];
        foreach ([['task', 'A'], ['problem', 'A'], ['task', 'B']] as [$type, $id]) {
            $ticket = (new Ticket())->setSubject('Routing')->setTicketType($type)->setAccount('Company', $id);
            $em->persist($ticket);
            $tickets[] = $ticket;
        }
        $em->flush();
        $conditions = [['field' => 'ticket_type', 'value' => 'task'], ['field' => 'account', 'value' => $account]];
        $actions = [['type' => 'change_priority', 'value' => 'urgent']];
        if ('workflow' === $engine) {
            $rule = (new Workflow())->setName('Routing')->setTriggerEvent('ticket.updated')->setConditions($conditions)->setActions($actions)->setIsActive(true);
            $em->persist($rule);
            $em->flush();
            foreach ($tickets as $ticket) {
                self::getContainer()->get(WorkflowEngine::class)->processEvent('ticket.updated', $ticket);
            }
        } elseif ('automation' === $engine) {
            $em->persist((new Automation())->setName('Routing')->setConditions($conditions)->setActions($actions)->setActive(true));
            $em->flush();
            self::assertSame($matched, self::getContainer()->get(AutomationRunner::class)->run());
        } else {
            $em->persist((new EscalationRule())->setName('Routing')->setConditions($conditions)->setActions($actions)->setIsActive(true));
            $em->flush();
            self::assertSame($matched, self::getContainer()->get(EscalationService::class)->evaluateRules());
        }
        self::assertCount($matched, array_filter($tickets, fn ($t) => 'urgent' === $t->getPriority()));
    }

    public function testRequesterAndSubjectResolversPopulateButNeverOverwriteAccounts(): void
    {
        self::bootKernel(['escalated' => ['accounts' => ['enabled' => true, 'resolver' => TestAccountResolver::class]]]);
        self::createSchema();
        $resolver = self::getContainer()->get(TestAccountResolver::class);
        $resolver->accounts = ['requester:7' => ['type' => 'Company', 'id' => 'A'], 'subject:8' => ['type' => 'Company', 'id' => 'B']];
        $service = self::getContainer()->get(TicketService::class);
        $ticket = $service->create(['subject' => 'Account', 'requester_id' => 7, 'requester_class' => 'HostUser']);
        self::assertSame('A', $ticket->getAccountId());
        self::getContainer()->get(TicketSubjectService::class)->attach($ticket, 'Shipment', 8, enforceAllowlist: false);
        self::assertSame('A', $ticket->getAccountId());
        $blank = $service->create(['subject' => 'No requester']);
        self::getContainer()->get(TicketSubjectService::class)->attach($blank, 'Shipment', 8, enforceAllowlist: false);
        self::assertSame('B', $blank->getAccountId());
    }

    public function testTargetedSlasSelectSpecificityThenIdAndKeepCreationBasedClocks(): void
    {
        self::bootKernel(['escalated' => ['targeted_slas' => true]]);
        self::createSchema();
        $em = self::entityManager();
        $department = (new Department())->setName('Delivery')->setSlug('delivery');
        $em->persist($department);
        $em->flush();
        $policies = [];
        foreach ([[null, null, true, 24], [null, 'task', false, 8], [$department, null, false, 4], [$department, 'task', false, 1], [$department, 'task', false, 2]] as [$dept, $type, $default, $hours]) {
            $policy = (new SlaPolicy())->setName('Target')->setDepartment($dept)->setTicketType($type)->setIsDefault($default)->setFirstResponseHours(['medium' => $hours]);
            $em->persist($policy);
            $policies[] = $policy;
        }
        $em->flush();
        $ticket = self::getContainer()->get(TicketService::class)->create(['subject' => 'Urgent stream', 'department_id' => $department->getId(), 'ticket_type' => 'task']);
        self::assertSame($policies[3], $ticket->getSlaPolicy());
        self::assertSame($ticket->getCreatedAt()->getTimestamp() + 3600, $ticket->getFirstResponseDueAt()->getTimestamp());
        $due = $ticket->getFirstResponseDueAt();
        $ticket->setDepartment(null);
        $em->flush();
        self::assertSame($due, $ticket->getFirstResponseDueAt());
        self::getContainer()->get(SlaService::class)->attachDefaultPolicy($ticket);
        self::assertSame($policies[1], $ticket->getSlaPolicy());
        self::assertSame($ticket->getCreatedAt()->getTimestamp() + 8 * 3600, $ticket->getFirstResponseDueAt()->getTimestamp());
        $ticket->setTicketType('problem');
        self::getContainer()->get(SlaService::class)->attachDefaultPolicy($ticket);
        self::assertSame($policies[0], $ticket->getSlaPolicy());
    }

    public function testTargetedSlasAndAccountsAreDisabledByDefault(): void
    {
        self::bootKernel(['escalated' => ['accounts' => ['resolver' => TestAccountResolver::class]]]);
        self::createSchema();
        self::getContainer()->get(TestAccountResolver::class)->accounts = ['requester:7' => ['type' => 'Company', 'id' => 'A']];
        $target = (new SlaPolicy())->setName('Target')->setTicketType('task')->setIsDefault(true);
        self::entityManager()->persist($target);
        self::entityManager()->flush();
        $ticket = self::getContainer()->get(TicketService::class)->create(['subject' => 'Legacy', 'ticket_type' => 'task', 'requester_id' => 7]);
        self::assertNull($ticket->getAccountId());
        self::assertNull($ticket->getSlaPolicy());
        self::getContainer()->get(SlaService::class)->attachDefaultPolicy($ticket);
        self::assertNull($ticket->getSlaPolicy());
    }
}
