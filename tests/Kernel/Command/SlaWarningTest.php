<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Command;

use Escalated\Symfony\Entity\AgentNotification;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Entity\WorkflowLog;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * `sla.warning`, as escalated-laravel's SlaService::checkWarnings from the
 * every-minute SLA check: a first-response or resolution deadline due within
 * 30 minutes fires `sla.warning` (to workflows, webhooks, plugins and the
 * assignee's `sla_warning` notification). Nothing emitted it before.
 */
final class SlaWarningTest extends EscalatedKernelTestCase
{
    private ApplicationTester $console;

    /** @var list<TicketWorkflowEvent> */
    private array $warnings = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $this->console = new ApplicationTester($application);

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TicketWorkflowEvent::class, function (TicketWorkflowEvent $event): void {
            if ('sla.warning' === $event->triggerName) {
                $this->warnings[] = $event;
            }
        });
    }

    public function testTheSlaCheckWarnsOfDeadlinesDueWithinThirtyMinutes(): void
    {
        $soon = $this->ticket('Due soon', firstResponseDueAt: new \DateTimeImmutable('+20 minutes'), assignedTo: 7);
        $this->ticket('Due later', firstResponseDueAt: new \DateTimeImmutable('+2 hours'));
        $this->ticket('Already overdue', firstResponseDueAt: new \DateTimeImmutable('-5 minutes'));
        $this->ticket('Answered', firstResponseDueAt: new \DateTimeImmutable('+10 minutes'), firstResponseAt: new \DateTimeImmutable('-1 minute'));
        $resolving = $this->ticket('Resolution soon', resolutionDueAt: new \DateTimeImmutable('+5 minutes'));
        $this->ticket('Resolved', resolutionDueAt: new \DateTimeImmutable('+5 minutes'), status: Ticket::STATUS_RESOLVED);

        $exitCode = $this->console->run(['command' => 'escalated:check-sla-breaches'], ['interactive' => false]);

        self::assertSame(0, $exitCode, $this->console->getDisplay());
        $fired = array_map(static fn (TicketWorkflowEvent $e): array => [$e->ticket->getId(), $e->context['warning_type']], $this->warnings);
        sort($fired);
        $expected = [[$soon->getId(), 'first_response'], [$resolving->getId(), 'resolution']];
        sort($expected);
        self::assertSame($expected, $fired);

        $first = $this->warnings[array_search($soon->getId(), array_map(static fn ($e) => $e->ticket->getId(), $this->warnings), true)];
        self::assertGreaterThanOrEqual(18, $first->context['minutes_remaining']);
        self::assertLessThanOrEqual(20, $first->context['minutes_remaining']);

        // The assignee hears of it in the agent feed.
        $notifications = self::entityManager()->getRepository(AgentNotification::class)->findBy(['userId' => 7]);
        self::assertSame(['sla_warning'], array_map(static fn (AgentNotification $n): string => $n->getType(), $notifications));
    }

    public function testAnSlaWarningWorkflowRuns(): void
    {
        $workflow = (new Workflow())
            ->setName('Warn the team')
            ->setTriggerEvent('sla.warning')
            ->setConditions([])
            ->setActions([['type' => 'add_note', 'value' => 'SLA due soon on {{reference}}']]);
        $em = self::entityManager();
        $em->persist($workflow);
        $em->flush();
        $this->ticket('Due soon', firstResponseDueAt: new \DateTimeImmutable('+10 minutes'));

        $this->console->run(['command' => 'escalated:check-sla-breaches'], ['interactive' => false]);

        self::assertSame(1, $em->getRepository(WorkflowLog::class)->count(['workflow' => $workflow]));
    }

    public function testSlaWarningIsOfferedToWebhooksAndWorkflows(): void
    {
        self::assertContains(EventNames::SLA_WARNING, EventNames::EMITTED);
    }

    public function testNoWarningsWhileSlaIsOff(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel(['escalated' => ['sla' => ['enabled' => false]]]);
        self::createSchema();
        $this->ticket('Due soon', firstResponseDueAt: new \DateTimeImmutable('+10 minutes'));

        $service = self::getContainer()->get(\Escalated\Symfony\Service\SlaService::class);
        self::assertSame(0, $service->checkWarnings());
    }

    private function ticket(
        string $subject,
        ?\DateTimeImmutable $firstResponseDueAt = null,
        ?\DateTimeImmutable $resolutionDueAt = null,
        ?\DateTimeImmutable $firstResponseAt = null,
        string $status = Ticket::STATUS_OPEN,
        int|string|null $assignedTo = null,
    ): Ticket {
        $ticket = (new Ticket())
            ->setSubject($subject)
            ->setPriority(Ticket::PRIORITY_LOW)
            ->setStatus($status)
            ->setReference('ESC-W'.bin2hex(random_bytes(4)))
            ->setFirstResponseDueAt($firstResponseDueAt)
            ->setResolutionDueAt($resolutionDueAt)
            ->setAssignedTo($assignedTo);
        if (null !== $firstResponseAt) {
            $ticket->setFirstResponseAt($firstResponseAt);
        }
        $em = self::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }
}
