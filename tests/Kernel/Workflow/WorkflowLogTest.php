<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Workflow;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Entity\WorkflowLog;
use Escalated\Symfony\Service\WorkflowEngine;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;

/**
 * WorkflowEngine wrote its execution log with a raw insert of a `status`
 * column, which the WorkflowLog entity (conditions_matched, started_at,
 * completed_at -- the NestJS reference shape) does not have. On a schema
 * built from the entities every run failed to log; on a migrated schema the
 * entity could not read the rows the engine wrote.
 */
final class WorkflowLogTest extends EscalatedKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();
    }

    public function testAMatchedRunIsLoggedInTheEntityShape(): void
    {
        $ticket = $this->ticket('Printer on fire');
        $workflow = $this->workflow(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'fire']]]);

        $this->engine()->processEvent('ticket.created', $ticket);

        $log = $this->onlyLog();
        self::assertSame($workflow->getId(), $log->getWorkflow()?->getId());
        self::assertSame($ticket->getId(), $log->getTicket()?->getId());
        self::assertSame('ticket.created', $log->getTriggerEvent());
        self::assertTrue($log->isConditionsMatched());
        self::assertNull($log->getErrorMessage());
        self::assertNotNull($log->getStartedAt());
        self::assertNotNull($log->getCompletedAt());
        self::assertGreaterThanOrEqual($log->getStartedAt(), $log->getCompletedAt());
    }

    public function testAnUnmatchedRunIsLoggedAsNotMatched(): void
    {
        $ticket = $this->ticket('Password reset');
        $this->workflow(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'fire']]]);

        $this->engine()->processEvent('ticket.created', $ticket);

        $log = $this->onlyLog();
        self::assertFalse($log->isConditionsMatched());
        self::assertSame([], $log->getActionsExecuted());
        self::assertNull($log->getStartedAt());
        self::assertNull($log->getCompletedAt());
    }

    private function engine(): WorkflowEngine
    {
        $engine = self::getContainer()->get(WorkflowEngine::class);
        self::assertInstanceOf(WorkflowEngine::class, $engine);

        return $engine;
    }

    private function onlyLog(): WorkflowLog
    {
        $em = self::entityManager();
        $em->clear();
        $logs = $em->getRepository(WorkflowLog::class)->findAll();
        self::assertCount(1, $logs, 'expected exactly one workflow log row');

        return $logs[0];
    }

    private function ticket(string $subject): Ticket
    {
        $ticket = (new Ticket())->setSubject($subject)->setReference('ESC-'.bin2hex(random_bytes(4)));
        $em = self::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    /**
     * @param array<string, mixed> $conditions
     */
    private function workflow(array $conditions): Workflow
    {
        $workflow = (new Workflow())
            ->setName('Escalate fires')
            ->setTriggerEvent('ticket.created')
            ->setConditions($conditions)
            ->setActions([['type' => 'change_priority', 'value' => 'urgent']]);
        $em = self::entityManager();
        $em->persist($workflow);
        $em->flush();

        return $workflow;
    }
}
