<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\WorkflowEngine;
use Psr\Log\LoggerInterface;

/**
 * Bridges Doctrine's lifecycle events to the WorkflowEngine.
 *
 * Previously the WorkflowEngine was defined (with a full
 * processEvent + executeActions implementation) but orphaned —
 * nothing invoked it. Workflows configured in the admin UI
 * didn't fire on ticket events.
 *
 * This listener currently wires the ticket.created trigger only,
 * via Doctrine's postPersist event. Other triggers
 * (ticket.updated, status_changed, assigned, priority_changed,
 * replied, escalated, sla.*) require TicketService to dispatch
 * corresponding events first — tracked as a follow-up.
 *
 * Mirrors the pattern used in:
 *   - escalated-nestjs WorkflowListener
 *   - escalated-laravel ProcessWorkflows
 *   - escalated-rails WorkflowSubscriber (PR #42)
 *   - escalated-django workflow_handlers (PR #39)
 *
 * Engine errors are caught + warn-logged so a misconfigured
 * workflow never breaks the persist chain.
 */
#[AsDoctrineListener(event: Events::postPersist)]
class WorkflowTicketListener
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Ticket) {
            return;
        }
        $this->handleTicketCreated($entity);
    }

    /**
     * Extracted from postPersist so tests can exercise the error-handling
     * path without needing to construct a final PostPersistEventArgs.
     */
    public function handleTicketCreated(Ticket $ticket): void
    {
        try {
            $this->engine->processEvent('ticket.created', $ticket);
        } catch (\Throwable $e) {
            $this->logger->warning(
                sprintf(
                    '[Escalated\\WorkflowTicketListener] ticket.created workflow failed for ticket #%s: %s',
                    $ticket->getId() ?? '?',
                    $e->getMessage(),
                ),
            );
        }
    }
}
