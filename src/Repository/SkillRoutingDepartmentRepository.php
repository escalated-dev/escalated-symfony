<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\SkillRoutingDepartment;

/**
 * @extends ServiceEntityRepository<SkillRoutingDepartment>
 */
class SkillRoutingDepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SkillRoutingDepartment::class);
    }
}
