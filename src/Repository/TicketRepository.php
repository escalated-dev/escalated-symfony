<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\Ticket;

/**
 * @extends ServiceEntityRepository<Ticket>
 *
 * @method Ticket|null find($id, $lockMode = null, $lockVersion = null)
 * @method Ticket|null findOneBy(array $criteria, array $orderBy = null)
 * @method Ticket[]    findAll()
 * @method Ticket[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ticket::class);
    }

    public function findByReference(string $reference): ?Ticket
    {
        return $this->findOneBy(['reference' => $reference]);
    }

    /**
     * @return Ticket[]
     */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status NOT IN (:closedStatuses)')
            ->setParameter('closedStatuses', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->andWhere('t.deletedAt IS NULL')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Ticket[]
     */
    public function findUnassigned(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.assignedTo IS NULL')
            ->andWhere('t.status NOT IN (:closedStatuses)')
            ->setParameter('closedStatuses', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->andWhere('t.deletedAt IS NULL')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Ticket[]
     */
    public function findAssignedTo(int $agentId): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.assignedTo = :agentId')
            ->setParameter('agentId', $agentId)
            ->andWhere('t.deletedAt IS NULL')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Ticket[]
     */
    public function findByRequester(int $requesterId, ?string $requesterClass = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.requesterId = :requesterId')
            ->setParameter('requesterId', $requesterId)
            ->andWhere('t.deletedAt IS NULL')
            ->orderBy('t.createdAt', 'DESC');

        if (null !== $requesterClass) {
            $qb->andWhere('t.requesterClass = :requesterClass')
                ->setParameter('requesterClass', $requesterClass);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Build a filtered query for listing tickets.
     *
     * @param array{
     *     status?: string,
     *     priority?: string,
     *     department_id?: int,
     *     assigned_to?: int,
     *     search?: string,
     *     sort_by?: string,
     *     sort_dir?: string,
     * } $filters
     */
    public function createFilteredQueryBuilder(array $filters = []): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.deletedAt IS NULL');

        if (!empty($filters['status'])) {
            $qb->andWhere('t.status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['priority'])) {
            $qb->andWhere('t.priority = :priority')
                ->setParameter('priority', $filters['priority']);
        }

        if (!empty($filters['department_id'])) {
            $qb->andWhere('t.department = :departmentId')
                ->setParameter('departmentId', $filters['department_id']);
        }

        if (!empty($filters['assigned_to'])) {
            $qb->andWhere('t.assignedTo = :assignedTo')
                ->setParameter('assignedTo', $filters['assigned_to']);
        }

        if (!empty($filters['search'])) {
            $qb->andWhere('t.subject LIKE :search OR t.reference LIKE :search OR t.description LIKE :search')
                ->setParameter('search', '%'.$filters['search'].'%');
        }

        $sortBy = $filters['sort_by'] ?? 'createdAt';
        $sortDir = strtoupper($filters['sort_dir'] ?? 'DESC');
        $sortDir = in_array($sortDir, ['ASC', 'DESC'], true) ? $sortDir : 'DESC';

        $qb->orderBy('t.'.$sortBy, $sortDir);

        return $qb;
    }

    /**
     * @return Ticket[]
     */
    public function findWithBreachedSla(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.slaFirstResponseBreached = true OR t.slaResolutionBreached = true')
            ->andWhere('t.status NOT IN (:closedStatuses)')
            ->setParameter('closedStatuses', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->andWhere('t.deletedAt IS NULL')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countOpenByAgent(int $agentId): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.assignedTo = :agentId')
            ->setParameter('agentId', $agentId)
            ->andWhere('t.status NOT IN (:closedStatuses)')
            ->setParameter('closedStatuses', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->andWhere('t.deletedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
