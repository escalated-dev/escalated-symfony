<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\SavedView;

/**
 * @extends ServiceEntityRepository<SavedView>
 *
 * @method SavedView|null find($id, $lockMode = null, $lockVersion = null)
 * @method SavedView|null findOneBy(array $criteria, array $orderBy = null)
 * @method SavedView[]    findAll()
 * @method SavedView[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SavedViewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedView::class);
    }

    /**
     * Get all views accessible by a user (own + shared).
     *
     * @return SavedView[]
     */
    public function findForUser(int $userId): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.userId = :userId OR v.isShared = true')
            ->setParameter('userId', $userId)
            ->orderBy('v.position', 'ASC')
            ->addOrderBy('v.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get only shared views.
     *
     * @return SavedView[]
     */
    public function findShared(): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.isShared = true')
            ->orderBy('v.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get only views owned by a specific user.
     *
     * @return SavedView[]
     */
    public function findOwnedBy(int $userId): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('v.position', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the default view for a user.
     */
    public function findDefaultForUser(int $userId): ?SavedView
    {
        return $this->createQueryBuilder('v')
            ->where('v.userId = :userId')
            ->andWhere('v.isDefault = true')
            ->setParameter('userId', $userId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
