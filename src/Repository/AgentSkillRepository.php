<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\AgentSkill;

/**
 * @extends ServiceEntityRepository<AgentSkill>
 */
class AgentSkillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgentSkill::class);
    }
}
