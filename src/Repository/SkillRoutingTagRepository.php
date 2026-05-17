<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\SkillRoutingTag;

/**
 * @extends ServiceEntityRepository<SkillRoutingTag>
 */
class SkillRoutingTagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SkillRoutingTag::class);
    }
}
