<?php

declare(strict_types=1);

namespace Escalated\Symfony\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\Skill;

/**
 * @extends ServiceEntityRepository<Skill>
 */
class SkillRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Skill::class);
    }

    public function findOneByName(string $name): ?Skill
    {
        return $this->findOneBy(['name' => $name]);
    }

    public function existsOtherWithName(string $name, ?int $excludeId): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.name = :name')
            ->setParameter('name', $name);

        if (null !== $excludeId) {
            $qb->andWhere('s.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function existsOtherWithSlug(string $slug, ?int $excludeId): bool
    {
        $qb = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.slug = :slug')
            ->setParameter('slug', $slug);

        if (null !== $excludeId) {
            $qb->andWhere('s.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int, array{agents: int, routing_tags: int, routing_departments: int}>
     */
    public function aggregateCountsBySkillId(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $agents = $conn->fetchAllAssociative(
            'SELECT skill_id, COUNT(*) AS c FROM escalated_agent_skills WHERE skill_id IN (?) GROUP BY skill_id',
            [$ids],
            [ArrayParameterType::INTEGER]
        );

        $tags = $conn->fetchAllAssociative(
            'SELECT skill_id, COUNT(*) AS c FROM escalated_skill_routing_tags WHERE skill_id IN (?) GROUP BY skill_id',
            [$ids],
            [ArrayParameterType::INTEGER]
        );

        $depts = $conn->fetchAllAssociative(
            'SELECT skill_id, COUNT(*) AS c FROM escalated_skill_routing_departments WHERE skill_id IN (?) GROUP BY skill_id',
            [$ids],
            [ArrayParameterType::INTEGER]
        );

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['agents' => 0, 'routing_tags' => 0, 'routing_departments' => 0];
        }
        foreach ($agents as $row) {
            $sid = (int) $row['skill_id'];
            $out[$sid]['agents'] = (int) $row['c'];
        }
        foreach ($tags as $row) {
            $sid = (int) $row['skill_id'];
            $out[$sid]['routing_tags'] = (int) $row['c'];
        }
        foreach ($depts as $row) {
            $sid = (int) $row['skill_id'];
            $out[$sid]['routing_departments'] = (int) $row['c'];
        }

        return $out;
    }
}
