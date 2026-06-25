<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-agent, per-channel concurrent-ticket capacity. Tracks how many open
 * tickets an agent is carrying (`current_count`) against their configured
 * ceiling (`max_concurrent`) so routing can avoid overloading. Mirrors the
 * Laravel AgentCapacity model.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_agent_capacity')]
#[ORM\UniqueConstraint(name: 'escalated_agent_capacity_user_channel_unique', columns: ['user_id', 'channel'])]
#[ORM\HasLifecycleCallbacks]
class AgentCapacity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 255)]
    private string $userId = '';

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $channel = 'default';

    #[ORM\Column(name: 'max_concurrent', type: Types::INTEGER, options: ['default' => 10])]
    private int $maxConcurrent = 10;

    #[ORM\Column(name: 'current_count', type: Types::INTEGER, options: ['default' => 0])]
    private int $currentCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Whether the agent has headroom for another ticket.
     */
    public function hasCapacity(): bool
    {
        return $this->currentCount < $this->maxConcurrent;
    }

    /**
     * Current load as a percentage of the ceiling (0 ceiling → fully loaded).
     */
    public function loadPercentage(): float
    {
        if ($this->maxConcurrent <= 0) {
            return 100.0;
        }

        return round(($this->currentCount / $this->maxConcurrent) * 100, 1);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): static
    {
        $this->userId = $userId;

        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getMaxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    public function setMaxConcurrent(int $maxConcurrent): static
    {
        $this->maxConcurrent = $maxConcurrent;

        return $this;
    }

    public function getCurrentCount(): int
    {
        return $this->currentCount;
    }

    public function setCurrentCount(int $currentCount): static
    {
        $this->currentCount = max(0, $currentCount);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
