<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ChatRoutingRule;
use Escalated\Symfony\Entity\ChatSession;

class ChatAvailabilityService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChatRoutingService $routingService,
    ) {
    }

    /**
     * Check whether live chat is currently available.
     *
     * Chat is available when at least one routing rule is active
     * and at least one agent in those rules is below their concurrent-chat limit.
     */
    public function isAvailable(): bool
    {
        $rules = $this->em->getRepository(ChatRoutingRule::class)
            ->findBy(['isActive' => true]);

        foreach ($rules as $rule) {
            $agentIds = $rule->getAgentIds();
            if (empty($agentIds)) {
                continue;
            }

            foreach ($agentIds as $agentId) {
                if ($this->routingService->getAgentActiveChatCount($agentId) < $rule->getMaxConcurrentChats()) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the number of visitors waiting in the chat queue.
     */
    public function getQueueLength(): int
    {
        return (int) $this->em->getRepository(ChatSession::class)
            ->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.status = :status')
            ->setParameter('status', ChatSession::STATUS_WAITING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Return availability status info for the widget.
     */
    public function getStatus(): array
    {
        return [
            'available' => $this->isAvailable(),
            'queue_length' => $this->getQueueLength(),
        ];
    }
}
