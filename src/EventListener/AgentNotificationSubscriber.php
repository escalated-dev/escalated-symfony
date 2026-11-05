<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Service\AgentPanel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Turns ticket events into the agents' in-app notifications (ADR
 * 2026-11-05-agent-panel-data, section 4): assignment, customer-side replies
 * (to the assignee and followers), SLA breaches and chat transfers. The agent
 * who caused an event is never notified of it, and a failure here never fails
 * the change that raised the event.
 */
class AgentNotificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AgentPanel $panel,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [TicketWorkflowEvent::class => 'onEvent'];
    }

    public function onEvent(TicketWorkflowEvent $event): void
    {
        try {
            $this->handle($event);
        } catch (\Throwable $e) {
            $this->logger->warning('[Escalated] agent notification failed: '.$e->getMessage());
        }
    }

    private function handle(TicketWorkflowEvent $event): void
    {
        $ticket = $event->ticket;
        $context = $event->context;

        match ($event->triggerName) {
            'ticket.assigned' => $this->panel->notify($context['agent_id'] ?? null, 'ticket_assigned', $ticket, [], $context['causer_id'] ?? null),
            'reply.created' => $this->reply($event),
            'sla.warning' => $this->panel->notify($ticket->getAssignedTo(), 'sla_warning', $ticket, ['sla_type' => $context['warning_type'] ?? null]),
            'sla.breached' => $this->panel->notify($ticket->getAssignedTo(), 'sla_breached', $ticket, ['sla_type' => $context['breach_type'] ?? null]),
            'chat.transferred' => $this->panel->notify($context['to_agent_id'] ?? null, 'chat_transferred', $ticket, ['session_id' => $context['chat_session_id'] ?? null], $context['from_agent_id'] ?? null),
            default => null,
        };
    }

    private function reply(TicketWorkflowEvent $event): void
    {
        $id = $event->context['reply_id'] ?? null;
        $reply = null !== $id ? $this->em->find(Reply::class, $id) : null;
        if (null === $reply || !$this->panel->isCustomerSide($reply)) {
            return;
        }
        foreach ($this->panel->replyRecipients($event->ticket) as $agentId) {
            $this->panel->notify($agentId, 'ticket_reply', $event->ticket, [], $reply->getAuthorId());
        }
    }
}
