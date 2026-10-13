<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Security\TicketAccess;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[AsDoctrineListener(event: Events::onFlush)]
final class TicketWriteGuard
{
    public function __construct(private readonly TicketAccess $access)
    {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        if (!$this->access->restricted()) {
            return;
        }
        $em = $event->getObjectManager();
        $unit = $em->getUnitOfWork();
        foreach ([...$unit->getScheduledEntityUpdates(), ...$unit->getScheduledEntityDeletions(), ...$unit->getScheduledEntityInsertions()] as $entity) {
            $meta = $em->getClassMetadata($entity::class);
            if (!str_starts_with($meta->name, 'Escalated\\Symfony\\Entity\\')) {
                continue;
            }
            if (!$this->access->visibleEntity($entity)) {
                throw new NotFoundHttpException('Ticket not found.');
            }
            if ($entity instanceof Ticket) {
                $changes = $unit->getEntityChangeSet($entity);
                if (isset($changes['assignedTo']) && null !== $entity->getAssignedTo()) {
                    $this->access->assertAssignable($entity, $entity->getAssignedTo());
                }
                continue;
            }
            $meta = $em->getClassMetadata($entity::class);
            foreach ($meta->associationMappings as $field => $mapping) {
                if (!isset($mapping['joinColumns'])) {
                    continue;
                }
                $parent = $meta->getFieldValue($entity, $field);
                if (is_object($parent) && !$this->access->visibleEntity($parent)) {
                    throw new NotFoundHttpException('Ticket not found.');
                }
            }
            $parentId = $meta->hasField('ticketId') ? $meta->getFieldValue($entity, 'ticketId') : null;
            if ($entity instanceof \Escalated\Symfony\Entity\CustomFieldValue && 'ticket' === $entity->getEntityType()) {
                $parentId = $entity->getEntityId();
            }
            if (null !== $parentId) {
                $ticket = $em->getRepository(Ticket::class)->findOneBy(['id' => $parentId]);
                if (null === $ticket) {
                    throw new NotFoundHttpException('Ticket not found.');
                }
            }
        }
        foreach ([...$unit->getScheduledCollectionUpdates(), ...$unit->getScheduledCollectionDeletions()] as $collection) {
            if (!$this->access->visibleEntity($collection->getOwner())) {
                throw new NotFoundHttpException('Ticket not found.');
            }
        }
    }
}
