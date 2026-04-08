<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketActivity;
use Escalated\Symfony\Repository\TicketRepository;

class SnoozeService
{
    public const ACTIVITY_TYPE_SNOOZED = 'snoozed';
    public const ACTIVITY_TYPE_UNSNOOZED = 'unsnoozed';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketRepository $ticketRepository,
    ) {
    }

    /**
     * Snooze a ticket until a given date/time.
     *
     * The ticket's current status is saved so it can be restored on wake.
     */
    public function snooze(Ticket $ticket, \DateTimeImmutable $until, int $causerId): Ticket
    {
        if (!$ticket->isOpen()) {
            throw new \InvalidArgumentException('Cannot snooze a resolved or closed ticket.');
        }

        if ($until <= new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Snooze time must be in the future.');
        }

        $ticket->setStatusBeforeSnooze($ticket->getStatus());
        $ticket->setSnoozedUntil($until);
        $ticket->setSnoozedBy($causerId);
        $ticket->setStatus(Ticket::STATUS_SNOOZED);

        $this->em->flush();

        $this->logActivity($ticket, self::ACTIVITY_TYPE_SNOOZED, $causerId, [
            'snoozed_until' => $until->format(\DateTimeInterface::ATOM),
        ]);

        return $ticket;
    }

    /**
     * Unsnooze a ticket, restoring its previous status.
     */
    public function unsnooze(Ticket $ticket, ?int $causerId = null): Ticket
    {
        if (!$ticket->isSnoozed()) {
            throw new \InvalidArgumentException('Ticket is not snoozed.');
        }

        $previousStatus = $ticket->getStatusBeforeSnooze() ?? Ticket::STATUS_OPEN;

        $ticket->setStatus($previousStatus);
        $ticket->setSnoozedUntil(null);
        $ticket->setSnoozedBy(null);
        $ticket->setStatusBeforeSnooze(null);

        $this->em->flush();

        $this->logActivity($ticket, self::ACTIVITY_TYPE_UNSNOOZED, $causerId, [
            'restored_status' => $previousStatus,
        ]);

        return $ticket;
    }

    /**
     * Find all tickets whose snooze period has expired and wake them.
     *
     * @return Ticket[] The tickets that were woken up
     */
    public function wakeExpiredTickets(): array
    {
        $qb = $this->ticketRepository->createQueryBuilder('t')
            ->where('t.status = :snoozed')
            ->andWhere('t.snoozedUntil <= :now')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('snoozed', Ticket::STATUS_SNOOZED)
            ->setParameter('now', new \DateTimeImmutable());

        $tickets = $qb->getQuery()->getResult();
        $woken = [];

        foreach ($tickets as $ticket) {
            $this->unsnooze($ticket);
            $woken[] = $ticket;
        }

        return $woken;
    }

    private function logActivity(Ticket $ticket, string $type, ?int $causerId, array $properties = []): void
    {
        $activity = new TicketActivity();
        $activity->setTicket($ticket);
        $activity->setType($type);
        $activity->setCauserId($causerId);
        $activity->setProperties(!empty($properties) ? $properties : null);

        $ticket->addActivity($activity);
        $this->em->persist($activity);
        $this->em->flush();
    }
}
