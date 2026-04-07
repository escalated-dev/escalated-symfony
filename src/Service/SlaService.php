<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Repository\TicketRepository;

class SlaService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketRepository $ticketRepository,
        private readonly bool $slaEnabled,
        private readonly bool $businessHoursOnly,
        private readonly array $businessHours,
    ) {
    }

    /**
     * Attach the default SLA policy to a ticket.
     */
    public function attachDefaultPolicy(Ticket $ticket): void
    {
        if (!$this->slaEnabled) {
            return;
        }

        $policy = $this->em->getRepository(SlaPolicy::class)
            ->findOneBy(['isDefault' => true, 'isActive' => true]);

        if (null === $policy) {
            return;
        }

        $this->attachPolicy($ticket, $policy);
    }

    /**
     * Attach a specific SLA policy to a ticket, calculating due dates.
     */
    public function attachPolicy(Ticket $ticket, SlaPolicy $policy): void
    {
        $ticket->setSlaPolicy($policy);

        $firstResponseHours = $policy->getFirstResponseHoursFor($ticket->getPriority());
        $resolutionHours = $policy->getResolutionHoursFor($ticket->getPriority());

        if (null !== $firstResponseHours) {
            $ticket->setFirstResponseDueAt(
                $this->calculateDueDate($ticket->getCreatedAt(), $firstResponseHours, $policy->isBusinessHoursOnly())
            );
        }

        if (null !== $resolutionHours) {
            $ticket->setResolutionDueAt(
                $this->calculateDueDate($ticket->getCreatedAt(), $resolutionHours, $policy->isBusinessHoursOnly())
            );
        }

        $this->em->flush();
    }

    /**
     * Check all open tickets for SLA breaches.
     *
     * @return int Number of newly breached tickets
     */
    public function checkBreaches(): int
    {
        if (!$this->slaEnabled) {
            return 0;
        }

        $breached = 0;
        $now = new \DateTimeImmutable();

        // Check first response breaches
        $qb = $this->em->createQueryBuilder();
        $tickets = $qb->select('t')
            ->from(Ticket::class, 't')
            ->where('t.status NOT IN (:closedStatuses)')
            ->setParameter('closedStatuses', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->andWhere('t.firstResponseDueAt IS NOT NULL')
            ->andWhere('t.firstResponseAt IS NULL')
            ->andWhere('t.slaFirstResponseBreached = false')
            ->andWhere('t.firstResponseDueAt < :now')
            ->setParameter('now', $now)
            ->andWhere('t.deletedAt IS NULL')
            ->getQuery()
            ->getResult();

        foreach ($tickets as $ticket) {
            $ticket->setSlaFirstResponseBreached(true);
            ++$breached;
        }

        // Check resolution breaches
        $qb = $this->em->createQueryBuilder();
        $tickets = $qb->select('t')
            ->from(Ticket::class, 't')
            ->where('t.status NOT IN (:closedStatuses)')
            ->setParameter('closedStatuses', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->andWhere('t.resolutionDueAt IS NOT NULL')
            ->andWhere('t.slaResolutionBreached = false')
            ->andWhere('t.resolutionDueAt < :now')
            ->setParameter('now', $now)
            ->andWhere('t.deletedAt IS NULL')
            ->getQuery()
            ->getResult();

        foreach ($tickets as $ticket) {
            $ticket->setSlaResolutionBreached(true);
            ++$breached;
        }

        if ($breached > 0) {
            $this->em->flush();
        }

        return $breached;
    }

    /**
     * Calculate a due date from a starting point, adding the given hours.
     */
    private function calculateDueDate(\DateTimeImmutable $from, float $hours, bool $businessHoursOnly): \DateTimeImmutable
    {
        if (!$businessHoursOnly) {
            return $from->modify(sprintf('+%d minutes', (int) ($hours * 60)));
        }

        $start = $this->businessHours['start'] ?? '09:00';
        $end = $this->businessHours['end'] ?? '17:00';
        $timezone = new \DateTimeZone($this->businessHours['timezone'] ?? 'UTC');
        $workDays = $this->businessHours['days'] ?? [1, 2, 3, 4, 5];

        $current = \DateTime::createFromImmutable($from)->setTimezone($timezone);
        $remainingMinutes = $hours * 60;

        while ($remainingMinutes > 0) {
            $dayOfWeek = (int) $current->format('N');

            if (in_array($dayOfWeek, $workDays, true)) {
                $dayStart = (clone $current)->modify($start);
                $dayEnd = (clone $current)->modify($end);

                if ($current < $dayStart) {
                    $current = $dayStart;
                }

                if ($current < $dayEnd) {
                    $availableMinutes = ($dayEnd->getTimestamp() - $current->getTimestamp()) / 60;

                    if ($availableMinutes >= $remainingMinutes) {
                        $current->modify(sprintf('+%d minutes', (int) $remainingMinutes));

                        return \DateTimeImmutable::createFromMutable($current);
                    }

                    $remainingMinutes -= $availableMinutes;
                }
            }

            $current->modify('+1 day')->modify($start);
        }

        return \DateTimeImmutable::createFromMutable($current);
    }
}
