<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Workflow;

use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\EscalationRule;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\Workflow;
use Escalated\Symfony\Entity\WorkflowLog;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Service\AssignmentService;
use Escalated\Symfony\Service\EscalationService;
use Escalated\Symfony\Service\TicketService;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * WorkflowEngine::TRIGGER_EVENTS offered triggers that nothing dispatched, so
 * a workflow saved against them never ran:
 *
 *  - replies were dispatched as `ticket.replied`, while the trigger list and
 *    the workflow admin contract call the event `reply.created`;
 *  - `ticket.reopened` and `ticket.department_changed` were never dispatched;
 *  - `reply.agent_reply` and `sla.warning` have no code path at all.
 *
 * The webhook admin had the same gap: it offered `ticket.unassigned`,
 * `note.created` and tag removal, which nothing emitted.
 */
final class WorkflowTriggersTest extends EscalatedKernelTestCase
{
    /** @var list<TicketWorkflowEvent> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();

        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(TicketWorkflowEvent::class, function (TicketWorkflowEvent $event): void {
            $this->dispatched[] = $event;
        });
    }

    public function testAReplyCreatedWorkflowFiresWhenAReplyIsAdded(): void
    {
        $ticket = $this->ticket('Printer on fire');
        $this->workflow('reply.created');

        $this->tickets()->addReply($ticket, 1, 'Any update?');

        self::assertSame(1, $this->logCount(), 'the reply.created workflow did not run');
    }

    public function testAWorkflowSavedWithTheLegacyTicketRepliedNameStillFires(): void
    {
        $ticket = $this->ticket('Printer on fire');
        $this->workflow('ticket.replied');

        $this->tickets()->addReply($ticket, 1, 'Any update?');

        self::assertSame(1, $this->logCount(), 'a workflow saved as ticket.replied stopped running');
    }

    public function testAnInternalNoteDoesNotFireReplyWorkflows(): void
    {
        $ticket = $this->ticket('Printer on fire');
        $this->workflow('reply.created');

        $this->tickets()->addReply($ticket, 1, 'Agents only', true);

        self::assertSame(0, $this->logCount());
    }

    public function testATicketReopenedWorkflowFiresOnReopen(): void
    {
        $ticket = $this->ticket('Printer on fire', Ticket::STATUS_RESOLVED);
        $this->workflow('ticket.reopened');

        $this->tickets()->reopen($ticket);

        self::assertSame(1, $this->logCount(), 'the ticket.reopened workflow did not run');
    }

    public function testADepartmentChangedWorkflowFiresWhenAnEscalationRuleMovesTheTicket(): void
    {
        $em = self::entityManager();
        $department = (new Department())->setName('Hardware')->setSlug('hardware');
        $em->persist($department);
        $ticket = $this->ticket('Printer on fire');
        $ticket->setPriority(Ticket::PRIORITY_URGENT);
        $rule = (new EscalationRule())
            ->setName('Urgent to hardware')
            ->setConditions([['field' => 'priority', 'value' => Ticket::PRIORITY_URGENT]])
            ->setActions([['type' => 'change_department', 'value' => 0]])
            ->setSortOrder(0)
            ->setIsActive(true);
        $em->persist($rule);
        $em->flush();
        $rule->setActions([['type' => 'change_department', 'value' => $department->getId()]]);
        $em->flush();
        $this->workflow('ticket.department_changed');

        $escalations = self::getContainer()->get(EscalationService::class);
        self::assertInstanceOf(EscalationService::class, $escalations);
        $escalations->evaluateRules();

        self::assertSame(1, $this->logCount(), 'the ticket.department_changed workflow did not run');
    }

    public function testUnassigningDispatchesTicketUnassigned(): void
    {
        $ticket = $this->ticket('Printer on fire');
        $ticket->setAssignedTo(7);
        self::entityManager()->flush();

        $assignments = self::getContainer()->get(AssignmentService::class);
        self::assertInstanceOf(AssignmentService::class, $assignments);
        $assignments->unassign($ticket, 3);

        $event = $this->onlyDispatched('ticket.unassigned');
        self::assertSame(7, $event->context['previous_agent_id'] ?? null);
    }

    public function testRemovingTagsDispatchesATagRemoval(): void
    {
        $em = self::entityManager();
        $tag = (new Tag())->setName('Hardware')->setSlug('hardware');
        $em->persist($tag);
        $ticket = $this->ticket('Printer on fire');
        $em->flush();
        $this->tickets()->addTags($ticket, [(int) $tag->getId()]);
        $this->dispatched = [];

        $this->tickets()->removeTags($ticket, [(int) $tag->getId()]);

        $event = $this->onlyDispatched('ticket.tagged');
        self::assertSame('removed', $event->context['action'] ?? null);
        self::assertSame([(int) $tag->getId()], $event->context['tag_ids'] ?? null);
    }

    public function testAnInternalNoteDispatchesNoteCreated(): void
    {
        $ticket = $this->ticket('Printer on fire');

        $note = $this->tickets()->addReply($ticket, 1, 'Agents only', true);

        $event = $this->onlyDispatched('note.created');
        self::assertSame($note->getId(), $event->context['reply_id'] ?? null);
    }

    private function onlyDispatched(string $triggerName): TicketWorkflowEvent
    {
        $matching = array_values(array_filter(
            $this->dispatched,
            static fn (TicketWorkflowEvent $event): bool => $event->triggerName === $triggerName,
        ));
        self::assertCount(1, $matching, sprintf(
            'expected one %s event; dispatched: [%s]',
            $triggerName,
            implode(', ', array_map(static fn (TicketWorkflowEvent $e): string => $e->triggerName, $this->dispatched)),
        ));

        return $matching[0];
    }

    private function logCount(): int
    {
        $em = self::entityManager();
        $em->clear();

        return $em->getRepository(WorkflowLog::class)->count([]);
    }

    private function tickets(): TicketService
    {
        $service = self::getContainer()->get(TicketService::class);
        self::assertInstanceOf(TicketService::class, $service);

        return $service;
    }

    private function ticket(string $subject, string $status = Ticket::STATUS_OPEN): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject($subject)
            ->setStatus($status)
            ->setReference('ESC-W'.bin2hex(random_bytes(4)));
        $em = self::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function workflow(string $trigger): void
    {
        $workflow = (new Workflow())
            ->setName('On '.$trigger)
            ->setTriggerEvent($trigger)
            ->setConditions(['all' => [['field' => 'subject', 'operator' => 'contains', 'value' => 'fire']]])
            ->setActions([['type' => 'add_note', 'value' => 'Workflow ran for {{reference}}']]);
        $em = self::entityManager();
        $em->persist($workflow);
        $em->flush();
    }
}
