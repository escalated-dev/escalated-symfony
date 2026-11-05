<?php

declare(strict_types=1);

namespace Escalated\Symfony\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Import\ImportContext;
use Escalated\Symfony\Service\AgentPanel;
use Psr\Log\LoggerInterface;

/**
 * Customer activity for per-agent unread markers (ADR
 * 2026-11-05-agent-panel-data): a new ticket (not an imported one), and every
 * customer-side public reply whatever path wrote it. The reply's ticket is
 * updated with plain SQL, which is safe mid-flush.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::postPersist)]
class AgentPanelActivityListener
{
    public function __construct(
        private readonly AgentPanel $panel,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof Ticket && null === $entity->getLastCustomerActivityAt() && !ImportContext::isImporting()) {
            $entity->setLastCustomerActivityAt(new \DateTimeImmutable());
        }
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Reply) {
            return;
        }
        try {
            $this->panel->replyCreated($entity);
        } catch (\Throwable $e) {
            $this->logger->warning('[Escalated] recording customer activity failed: '.$e->getMessage());
        }
    }
}
