<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;

/**
 * Builds the {@code payload} object carried by a webhook event.
 *
 * Kept separate from WebhookSubscriber so the ticket.created path (which runs
 * inside a Doctrine postPersist, mid-flush) can build its payload from the
 * in-memory entity without touching the database, while the richer trigger
 * mappings (tags, replies) can enrich the payload once the flush has settled.
 */
final class WebhookPayloadFactory
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * The base ticket payload. Safe to call mid-flush — reads in-memory
     * scalars only, no database access.
     *
     * @return array{ticket: array{id: int|null, reference: string, subject: string, status: string, priority: string}}
     */
    public function ticket(Ticket $ticket): array
    {
        return [
            'ticket' => [
                'id' => $ticket->getId(),
                'reference' => $ticket->getReference(),
                'subject' => $ticket->getSubject(),
                'status' => $ticket->getStatus(),
                'priority' => $ticket->getPriority(),
            ],
        ];
    }

    /**
     * Resolve a tag id to its {id, name} payload fragment. Performs a lookup,
     * so must not be called mid-flush.
     *
     * @return array{id: int, name: string|null}
     */
    public function tag(int $tagId): array
    {
        $tag = $this->em->find(Tag::class, $tagId);

        return ['id' => $tagId, 'name' => $tag?->getName()];
    }
}
