<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Event\TicketWorkflowEvent;
use Escalated\Symfony\Security\TicketAccess;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Merges one ticket into another, like escalated-laravel's TicketMergeService:
 * the source's replies and notes move to the target, both tickets get a
 * system note, and the source is closed with `merged_into_id` pointing at the
 * target. The source's close is announced as `ticket.status_changed` (and
 * `ticket.closed`) after the merge commits.
 */
class TicketMergeService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?TicketAccess $access = null,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when the tickets are the same, or either was already merged
     */
    public function merge(Ticket $source, Ticket $target, int|string|null $mergedBy = null): void
    {
        $this->access?->assertVisible($source);
        $this->access?->assertVisible($target);

        if ($source === $target || ($source->getId() ?? -1) === $target->getId()) {
            throw new \InvalidArgumentException('Cannot merge a ticket into itself.');
        }
        if (null !== $source->getMergedIntoId()) {
            throw new \InvalidArgumentException(sprintf('Ticket %s was already merged.', $source->getReference()));
        }
        if (null !== $target->getMergedIntoId()) {
            throw new \InvalidArgumentException(sprintf('Ticket %s was merged into another ticket.', $target->getReference()));
        }

        $oldStatus = $source->getStatus();

        $this->em->wrapInTransaction(function () use ($source, $target, $mergedBy): void {
            $this->em->createQueryBuilder()
                ->update(Reply::class, 'r')
                ->set('r.ticket', ':target')
                ->where('r.ticket = :source')
                ->setParameter('target', $target->getId())
                ->setParameter('source', $source->getId())
                ->getQuery()
                ->execute();

            $this->note($target, sprintf('Ticket %s was merged into this ticket.', $source->getReference()), [
                'system_note' => true,
                'merge_source' => $source->getReference(),
                'merged_by' => $mergedBy,
            ]);
            $this->note($source, sprintf('This ticket was merged into %s.', $target->getReference()), [
                'system_note' => true,
                'merge_target' => $target->getReference(),
                'merged_by' => $mergedBy,
            ]);

            $source->setStatus(Ticket::STATUS_CLOSED);
            $source->setClosedAt(new \DateTimeImmutable());
            $source->setMergedIntoId($target->getId());
            $this->em->flush();
        });

        // The in-memory reply collections predate the bulk move.
        $this->em->refresh($source);
        $this->em->refresh($target);

        if (Ticket::STATUS_CLOSED !== $oldStatus) {
            $this->dispatcher->dispatch(new TicketWorkflowEvent(
                'ticket.status_changed',
                $source,
                ['old_status' => $oldStatus, 'new_status' => Ticket::STATUS_CLOSED, 'causer_id' => $mergedBy, 'merged_into' => $target->getReference()],
            ));
        }
    }

    /** @param array<string, mixed> $metadata */
    private function note(Ticket $ticket, string $body, array $metadata): void
    {
        $reply = new Reply();
        $reply->setTicket($ticket);
        $reply->setBody($body);
        $reply->setIsInternalNote(true);
        $reply->setType('note');
        $reply->setMetadata($metadata);
        $this->em->persist($reply);
    }
}
