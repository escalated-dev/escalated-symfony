<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventListener;

use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Service\WorkflowEngine;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bridges TicketWorkflowEvent dispatches from TicketService into
 * WorkflowEngine::processEvent. Together with WorkflowTicketListener
 * (which covers ticket.created via Doctrine postPersist), this gives
 * Symfony coverage of every Workflow trigger available in NestJS /
 * Laravel / Rails / Django.
 *
 * Engine errors are caught + warn-logged so a misconfigured workflow
 * never disrupts the mutation that fired the event.
 */
final class WorkflowTriggerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [TicketWorkflowEvent::class => 'onTrigger'];
    }

    public function onTrigger(TicketWorkflowEvent $event): void
    {
        try {
            $this->engine->processEvent($event->triggerName, $event->ticket);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf(
                    '[Escalated\\WorkflowTriggerSubscriber] %s workflow failed for ticket #%s: %s',
                    $event->triggerName,
                    $event->ticket->getId() ?? '?',
                    $e->getMessage(),
                ),
            );
        }
    }
}
