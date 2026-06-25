<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\Ticket;

/**
 * CSAT — records a customer satisfaction rating against a ticket and
 * enforces the rateability rules. Mirrors the Laravel
 * SatisfactionRatingController logic:
 *
 *   - rating must be an integer 1-5,
 *   - the ticket must be resolved or closed,
 *   - a ticket may only be rated once.
 *
 * The "rated by" pointer is optional (null for guest submissions).
 */
class SatisfactionRatingService
{
    private const MIN_RATING = 1;
    private const MAX_RATING = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Whether a rating score is within the accepted 1-5 range. Pure.
     */
    public function isValidRating(int $rating): bool
    {
        return $rating >= self::MIN_RATING && $rating <= self::MAX_RATING;
    }

    /**
     * Whether a ticket is in a state that may be rated (resolved/closed). Pure.
     */
    public function ticketRateable(Ticket $ticket): bool
    {
        return \in_array($ticket->getStatus(), [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED], true);
    }

    /**
     * Whether the ticket already carries a rating.
     */
    public function hasRating(Ticket $ticket): bool
    {
        return null !== $this->em->getRepository(SatisfactionRating::class)
            ->findOneBy(['ticket' => $ticket]);
    }

    /**
     * Record a rating against a ticket.
     *
     * @throws \InvalidArgumentException when the score is out of range, the
     *                                   ticket is not resolved/closed, or it
     *                                   has already been rated
     */
    public function rate(
        Ticket $ticket,
        int $rating,
        ?string $comment = null,
        ?string $ratedByClass = null,
        ?string $ratedById = null,
    ): SatisfactionRating {
        if (!$this->isValidRating($rating)) {
            throw new \InvalidArgumentException('Rating must be an integer between 1 and 5.');
        }
        if (!$this->ticketRateable($ticket)) {
            throw new \InvalidArgumentException('Only resolved or closed tickets can be rated.');
        }
        if ($this->hasRating($ticket)) {
            throw new \InvalidArgumentException('This ticket has already been rated.');
        }

        $entity = (new SatisfactionRating())
            ->setTicket($ticket)
            ->setRating($rating)
            ->setComment('' !== (string) $comment ? $comment : null)
            ->setRatedByClass($ratedByClass)
            ->setRatedById($ratedById);

        $this->em->persist($entity);
        $this->em->flush();

        return $entity;
    }
}
