<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\TicketFollower;
use Escalated\Symfony\Service\FollowerRecipients;

/**
 * @extends ServiceEntityRepository<TicketFollower>
 */
class TicketFollowerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TicketFollower::class);
    }

    /**
     * User ids following a ticket, minus the actor and de-duplicated. The
     * package has no notification fan-out of its own; the host delivers to
     * these ids.
     *
     * @return list<string>
     */
    public function followerUserIds(int $ticketId, int|string|null $excludeUserId = null): array
    {
        $rows = $this->createQueryBuilder('f')
            ->select('f.userId')
            ->where('f.ticketId = :ticketId')
            ->setParameter('ticketId', $ticketId)
            ->getQuery()
            ->getScalarResult();

        return FollowerRecipients::resolve(array_column($rows, 'userId'), $excludeUserId);
    }
}
