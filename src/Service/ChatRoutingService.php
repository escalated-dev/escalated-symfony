<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ChatRoutingRule;
use Escalated\Symfony\Entity\ChatSession;
use Escalated\Symfony\Entity\Department;

class ChatRoutingService
{
    /** @var array<int, int> Round-robin index per rule */
    private array $roundRobinIndex = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Find the best available agent for a chat session.
     *
     * Evaluates active routing rules in priority order and returns the
     * first agent ID that passes concurrent-chat limits, or null if no
     * agent is available.
     */
    public function findAvailableAgent(?Department $department = null): ?int
    {
        $rules = $this->getActiveRules($department);

        foreach ($rules as $rule) {
            $agentId = $this->evaluateRule($rule);
            if (null !== $agentId) {
                return $agentId;
            }
        }

        return null;
    }

    /**
     * Count active chat sessions for a given agent.
     */
    public function getAgentActiveChatCount(int $agentId): int
    {
        return (int) $this->em->getRepository(ChatSession::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.agentId = :agentId')
            ->andWhere('s.status = :status')
            ->setParameter('agentId', $agentId)
            ->setParameter('status', ChatSession::STATUS_ACTIVE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return ChatRoutingRule[]
     */
    private function getActiveRules(?Department $department): array
    {
        $qb = $this->em->getRepository(ChatRoutingRule::class)
            ->createQueryBuilder('r')
            ->where('r.isActive = true')
            ->orderBy('r.priority', 'DESC');

        if (null !== $department) {
            $qb->andWhere('r.department IS NULL OR r.department = :dept')
                ->setParameter('dept', $department);
        }

        return $qb->getQuery()->getResult();
    }

    private function evaluateRule(ChatRoutingRule $rule): ?int
    {
        $agentIds = $rule->getAgentIds();
        if (empty($agentIds)) {
            return null;
        }

        $maxChats = $rule->getMaxConcurrentChats();

        return match ($rule->getStrategy()) {
            ChatRoutingRule::STRATEGY_ROUND_ROBIN => $this->roundRobin($rule, $agentIds, $maxChats),
            ChatRoutingRule::STRATEGY_LEAST_ACTIVE => $this->leastActive($agentIds, $maxChats),
            default => $this->roundRobin($rule, $agentIds, $maxChats),
        };
    }

    private function roundRobin(ChatRoutingRule $rule, array $agentIds, int $maxChats): ?int
    {
        $ruleId = $rule->getId() ?? 0;
        $index = $this->roundRobinIndex[$ruleId] ?? 0;

        for ($i = 0; $i < count($agentIds); ++$i) {
            $agentId = $agentIds[($index + $i) % count($agentIds)];
            if ($this->getAgentActiveChatCount($agentId) < $maxChats) {
                $this->roundRobinIndex[$ruleId] = ($index + $i + 1) % count($agentIds);

                return $agentId;
            }
        }

        return null;
    }

    private function leastActive(array $agentIds, int $maxChats): ?int
    {
        $bestAgent = null;
        $bestCount = PHP_INT_MAX;

        foreach ($agentIds as $agentId) {
            $count = $this->getAgentActiveChatCount($agentId);
            if ($count < $maxChats && $count < $bestCount) {
                $bestCount = $count;
                $bestAgent = $agentId;
            }
        }

        return $bestAgent;
    }
}
