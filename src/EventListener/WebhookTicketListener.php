<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\WebhookDispatcher;
use Escalated\Symfony\Service\WebhookPayloadFactory;
use Psr\Log\LoggerInterface;

/**
 * Fires the ticket.created webhook via Doctrine's postPersist event, so
 * guest-path submissions that don't travel through TicketService still emit it.
 *
 * postPersist runs mid-flush, so this only {@see WebhookDispatcher::enqueue()}s
 * the event (an in-memory buffer append) — it never writes a delivery row or
 * flushes here. The buffer is drained on terminate by WebhookSubscriber, which
 * mirrors how WorkflowTicketListener defers to the WorkflowEngine's raw-SQL
 * logging to stay flush-safe.
 *
 * Errors are caught + warn-logged so a webhook problem never breaks the persist.
 */
#[AsDoctrineListener(event: Events::postPersist)]
class WebhookTicketListener
{
    public function __construct(
        private readonly WebhookDispatcher $dispatcher,
        private readonly WebhookPayloadFactory $payloads,
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
     * Extracted from postPersist so tests can exercise the enqueue + error
     * handling without constructing a PostPersistEventArgs.
     */
    public function handleTicketCreated(Ticket $ticket): void
    {
        try {
            $this->dispatcher->enqueue('ticket.created', $this->payloads->ticket($ticket));
        } catch (\Throwable $e) {
            $this->logger->warning(sprintf(
                '[Escalated\\WebhookTicketListener] ticket.created webhook failed for ticket #%s: %s',
                $ticket->getId() ?? '?',
                $e->getMessage(),
            ));
        }
    }
}
