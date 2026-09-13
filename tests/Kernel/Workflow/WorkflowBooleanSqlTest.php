<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Workflow;

use Escalated\Symfony\Entity\DelayedAction;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Entity\WorkflowLog;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Service\WorkflowEngine;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;

/**
 * WorkflowEngine compared boolean columns to integer literals in raw SQL
 * (`is_active = 1`, `executed = 0`, `SET executed = 1`). MySQL and SQLite
 * coerce; PostgreSQL rejects `boolean = integer`. The trigger subscriber
 * swallowed the error, so on PostgreSQL:
 *
 *  - no workflow ever ran;
 *  - ticket.created runs inside the flush that inserts the ticket, where the
 *    failed statement aborts the transaction -- the ticket was rolled back;
 *  - delayed actions were never picked up.
 *
 * These pass on every database; CI runs them on PostgreSQL.
 */
final class WorkflowBooleanSqlTest extends EscalatedKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();
    }

    public function testAnActiveTicketUpdatedWorkflowRunsAndIsLogged(): void
    {
        $ticket = $this->ticket('Printer', Ticket::PRIORITY_LOW);
        $this->workflow('ticket.updated', 'fire', 'urgent');

        $this->tickets()->update($ticket, ['subject' => 'Printer on fire']);

        $em = self::entityManager();
        $em->clear();
        $logs = $em->getRepository(WorkflowLog::class)->findAll();
        self::assertCount(1, $logs, 'the ticket.updated workflow did not run');
        self::assertTrue($logs[0]->isConditionsMatched());

        $reloaded = $em->find(Ticket::class, $ticket->getId());
        self::assertSame('urgent', $reloaded?->getPriority());
    }

    public function testAnInactiveWorkflowDoesNotRun(): void
    {
        $ticket = $this->ticket('Printer', Ticket::PRIORITY_LOW);
        $this->workflow('ticket.updated', 'fire', 'urgent', active: false);

        $this->tickets()->update($ticket, ['subject' => 'Printer on fire']);

        $em = self::entityManager();
        $em->clear();
        self::assertSame(0, $em->getRepository(WorkflowLog::class)->count([]));
        self::assertSame(Ticket::PRIORITY_LOW, $em->find(Ticket::class, $ticket->getId())?->getPriority());
    }

    public function testATicketCreatedThroughTheServiceSurvivesItsCreatedWorkflow(): void
    {
        $this->workflow('ticket.created', 'fire', 'urgent');

        $ticket = $this->tickets()->create(['subject' => 'Printer on fire']);
        $id = $ticket->getId();

        $em = self::entityManager();
        $em->clear();
        $reloaded = $em->find(Ticket::class, $id);
        self::assertInstanceOf(Ticket::class, $reloaded, 'the created ticket was not persisted');
        self::assertSame(sprintf('ESC-%05d', $id), $reloaded->getReference());
        self::assertSame(1, $em->getRepository(WorkflowLog::class)->count([]), 'the ticket.created workflow did not run');
    }

    public function testDueDelayedActionsRunOnceAndAreMarkedExecuted(): void
    {
        $ticket = $this->ticket('Waiting on a part', Ticket::PRIORITY_LOW);
        $workflow = $this->workflow('ticket.updated', 'never-matches', 'urgent');

        $delayed = (new DelayedAction())
            ->setWorkflowId((int) $workflow->getId())
            ->setTicketId((int) $ticket->getId())
            ->setActionData(['type' => 'change_priority', 'value' => Ticket::PRIORITY_HIGH])
            ->setExecuteAt(new \DateTimeImmutable('-5 minutes'));
        $notYetDue = (new DelayedAction())
            ->setWorkflowId((int) $workflow->getId())
            ->setTicketId((int) $ticket->getId())
            ->setActionData(['type' => 'change_priority', 'value' => Ticket::PRIORITY_URGENT])
            ->setExecuteAt(new \DateTimeImmutable('+1 day'));
        $em = self::entityManager();
        $em->persist($delayed);
        $em->persist($notYetDue);
        $em->flush();

        $this->engine()->processDelayedActions();

        $em->clear();
        self::assertSame(Ticket::PRIORITY_HIGH, $em->find(Ticket::class, $ticket->getId())?->getPriority());
        self::assertTrue($this->executed((int) $delayed->getId()), 'the due action was not marked executed');
        self::assertFalse($this->executed((int) $notYetDue->getId()), 'an action that is not due ran');

        // A second pass must not run the executed action again.
        $reloaded = $em->find(Ticket::class, $ticket->getId());
        self::assertInstanceOf(Ticket::class, $reloaded);
        $reloaded->setPriority(Ticket::PRIORITY_LOW);
        $em->flush();

        $this->engine()->processDelayedActions();

        $em->clear();
        self::assertSame(Ticket::PRIORITY_LOW, $em->find(Ticket::class, $ticket->getId())?->getPriority());
    }

    private function executed(int $delayedActionId): bool
    {
        return (bool) self::entityManager()->getConnection()->fetchOne(
            'SELECT executed FROM escalated_delayed_actions WHERE id = ?',
            [$delayedActionId],
        );
    }

    private function tickets(): TicketService
    {
        $service = self::getContainer()->get(TicketService::class);
        self::assertInstanceOf(TicketService::class, $service);

        return $service;
    }

    private function engine(): WorkflowEngine
    {
        $engine = self::getContainer()->get(WorkflowEngine::class);
        self::assertInstanceOf(WorkflowEngine::class, $engine);

        return $engine;
    }

    private function ticket(string $subject, string $priority): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject($subject)
            ->setPriority($priority)
            ->setReference('ESC-T'.bin2hex(random_bytes(4)));
        $em = self::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function workflow(string $trigger, string $subjectContains, string $priority, bool $active = true): Workflow
    {
        $workflow = (new Workflow())
            ->setName('Escalate '.$subjectContains)
            ->setTriggerEvent($trigger)
            ->setConditions(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => $subjectContains]]])
            ->setActions([['type' => 'change_priority', 'value' => $priority]])
            ->setIsActive($active);
        $em = self::entityManager();
        $em->persist($workflow);
        $em->flush();

        return $workflow;
    }
}
