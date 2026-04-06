<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\Department;

/**
 * @extends ServiceEntityRepository<Department>
 *
 * @method Department|null find($id, $lockMode = null, $lockVersion = null)
 * @method Department|null findOneBy(array $criteria, array $orderBy = null)
 * @method Department[]    findAll()
 * @method Department[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    /**
     * @return Department[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.isActive = true')
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findBySlug(string $slug): ?Department
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
