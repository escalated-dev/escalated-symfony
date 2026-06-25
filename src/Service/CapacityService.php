<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AgentCapacity;

/**
 * Tracks per-agent, per-channel concurrent-ticket load so routing can avoid
 * overloading agents. Mirrors the Laravel CapacityService: a capacity row is
 * created on demand (default ceiling 10, count 0) and the running count is
 * incremented on assignment / decremented on release.
 */
class CapacityService
{
    private const DEFAULT_MAX_CONCURRENT = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Whether the agent can accept another ticket on the given channel.
     */
    public function canAcceptTicket(int|string $userId, string $channel = 'default'): bool
    {
        return $this->findOrCreate($userId, $channel)->hasCapacity();
    }

    /**
     * Increment the agent's running load.
     */
    public function incrementLoad(int|string $userId, string $channel = 'default'): void
    {
        $capacity = $this->findOrCreate($userId, $channel);
        $capacity->setCurrentCount($capacity->getCurrentCount() + 1);
        $this->em->flush();
    }

    /**
     * Decrement the agent's running load (never below zero).
     */
    public function decrementLoad(int|string $userId, string $channel = 'default'): void
    {
        $capacity = $this->findOrCreate($userId, $channel);
        if ($capacity->getCurrentCount() > 0) {
            $capacity->setCurrentCount($capacity->getCurrentCount() - 1);
            $this->em->flush();
        }
    }

    /**
     * All capacity rows, ordered by agent, for the admin view.
     *
     * @return AgentCapacity[]
     */
    public function getAllCapacities(): array
    {
        return $this->em->getRepository(AgentCapacity::class)
            ->findBy([], ['userId' => 'ASC', 'channel' => 'ASC']);
    }

    private function findOrCreate(int|string $userId, string $channel): AgentCapacity
    {
        $repo = $this->em->getRepository(AgentCapacity::class);
        $capacity = $repo->findOneBy(['userId' => (string) $userId, 'channel' => $channel]);

        if (null === $capacity) {
            $capacity = (new AgentCapacity())
                ->setUserId((string) $userId)
                ->setChannel($channel)
                ->setMaxConcurrent(self::DEFAULT_MAX_CONCURRENT)
                ->setCurrentCount(0);
            $this->em->persist($capacity);
            $this->em->flush();
        }

        return $capacity;
    }
}
