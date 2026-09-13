<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Command;

use Escalated\Symfony\Entity\DelayedAction;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * SlaService::checkBreaches() and WorkflowEngine::processDelayedActions() had
 * no caller: nothing marked an overdue ticket as breached, fired sla.breached,
 * or ran a workflow's delayed action. A host has no way to schedule a service
 * method, so each needs a console command.
 */
final class ScheduledCommandsTest extends EscalatedKernelTestCase
{
    private ApplicationTester $console;

    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $this->console = new ApplicationTester($application);
    }

    public function testCheckSlaBreachesMarksAnOverdueTicketAsBreached(): void
    {
        $overdue = $this->ticket('Overdue', new \DateTimeImmutable('-1 hour'));
        $onTime = $this->ticket('On time', new \DateTimeImmutable('+1 hour'));

        $exitCode = $this->console->run(['command' => 'escalated:check-sla-breaches'], ['interactive' => false]);

        self::assertSame(0, $exitCode, $this->console->getDisplay());
        self::assertStringContainsString('1', $this->console->getDisplay());

        $em = self::entityManager();
        $em->clear();
        self::assertTrue($em->find(Ticket::class, $overdue->getId())?->isSlaFirstResponseBreached());
        self::assertFalse($em->find(Ticket::class, $onTime->getId())?->isSlaFirstResponseBreached());
    }

    public function testProcessDelayedActionsRunsActionsThatAreDue(): void
    {
        $ticket = $this->ticket('Waiting on a part', null);
        $workflow = (new Workflow())
            ->setName('Bump later')
            ->setTriggerEvent('ticket.updated')
            ->setConditions([])
            ->setActions([]);
        $em = self::entityManager();
        $em->persist($workflow);
        $em->flush();

        $delayed = (new DelayedAction())
            ->setWorkflowId((int) $workflow->getId())
            ->setTicketId((int) $ticket->getId())
            ->setActionData(['type' => 'change_priority', 'value' => Ticket::PRIORITY_HIGH])
            ->setExecuteAt(new \DateTimeImmutable('-1 minute'));
        $em->persist($delayed);
        $em->flush();

        $exitCode = $this->console->run(['command' => 'escalated:process-delayed-actions'], ['interactive' => false]);

        self::assertSame(0, $exitCode, $this->console->getDisplay());

        $em->clear();
        self::assertSame(Ticket::PRIORITY_HIGH, $em->find(Ticket::class, $ticket->getId())?->getPriority());
    }

    private function ticket(string $subject, ?\DateTimeImmutable $firstResponseDueAt): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject($subject)
            ->setPriority(Ticket::PRIORITY_LOW)
            ->setReference('ESC-C'.bin2hex(random_bytes(4)))
            ->setFirstResponseDueAt($firstResponseDueAt);
        $em = self::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }
}
