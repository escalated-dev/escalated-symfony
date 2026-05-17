<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AgentProfile;
use Escalated\Symfony\Entity\AgentSkill;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Repository\TicketRepository;

/**
 * Explicit tag/department → skill routing per ADR 2026-05-13.
 */
class SkillRoutingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TicketRepository $ticketRepository,
    ) {
    }

    /**
     * Returns agent user ids best suited for the ticket, ordered by routing contract:
     * proficiency sum desc, then open ticket load asc.
     *
     * @return list<int>
     */
    public function findMatchingAgents(Ticket $ticket): array
    {
        $requiredSkillIds = $this->resolveRequiredSkillIds($ticket);
        if ([] === $requiredSkillIds) {
            return [];
        }

        $n = \count($requiredSkillIds);

        $qb = $this->em->createQueryBuilder();
        $qb->select('ags.userId AS uid', 'SUM(ags.proficiency) AS profSum')
            ->from(AgentSkill::class, 'ags')
            ->innerJoin(AgentProfile::class, 'ap', 'WITH', 'ap.userId = ags.userId')
            ->where('IDENTITY(ags.skill) IN (:skills)')
            ->setParameter('skills', $requiredSkillIds)
            ->groupBy('ags.userId')
            ->having('COUNT(DISTINCT IDENTITY(ags.skill)) = :n')
            ->setParameter('n', $n);

        $rows = $qb->getQuery()->getArrayResult();

        $candidates = [];
        foreach ($rows as $row) {
            $uid = (int) $row['uid'];
            $candidates[$uid] = [
                'user_id' => $uid,
                'proficiency_sum' => (int) $row['profSum'],
                'open' => $this->ticketRepository->countOpenByAgent($uid),
            ];
        }

        uasort($candidates, static function (array $a, array $b): int {
            if ($a['proficiency_sum'] !== $b['proficiency_sum']) {
                return $b['proficiency_sum'] <=> $a['proficiency_sum'];
            }

            return $a['open'] <=> $b['open'];
        });

        return array_values(array_map(static fn (array $c) => $c['user_id'], $candidates));
    }

    /**
     * @return list<int>
     */
    private function resolveRequiredSkillIds(Ticket $ticket): array
    {
        $tagIds = [];
        foreach ($ticket->getTags() as $tag) {
            $id = $tag->getId();
            if (null !== $id) {
                $tagIds[] = $id;
            }
        }
        $deptId = $ticket->getDepartment()?->getId();

        $conn = $this->em->getConnection();
        $skillIds = [];

        if ([] !== $tagIds) {
            $rows = $conn->fetchFirstColumn(
                'SELECT DISTINCT skill_id FROM escalated_skill_routing_tags WHERE tag_id IN (?)',
                [$tagIds],
                [ArrayParameterType::INTEGER]
            );
            foreach ($rows as $sid) {
                $skillIds[(int) $sid] = true;
            }
        }

        if (null !== $deptId) {
            $rows = $conn->fetchFirstColumn(
                'SELECT DISTINCT skill_id FROM escalated_skill_routing_departments WHERE department_id = ?',
                [$deptId]
            );
            foreach ($rows as $sid) {
                $skillIds[(int) $sid] = true;
            }
        }

        return array_map('intval', array_keys($skillIds));
    }
}
