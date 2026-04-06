<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_agent_profiles')]
#[ORM\UniqueConstraint(columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class AgentProfile
{
    public const TYPE_FULL = 'full';
    public const TYPE_LIGHT = 'light';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $userId;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $agentType = self::TYPE_FULL;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxTickets = null;

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

    public function isLightAgent(): bool
    {
        return $this->agentType === self::TYPE_LIGHT;
    }

    public function isFullAgent(): bool
    {
        return $this->agentType === self::TYPE_FULL;
    }

    // --- Getters and Setters ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getAgentType(): string
    {
        return $this->agentType;
    }

    public function setAgentType(string $agentType): self
    {
        $this->agentType = $agentType;
        return $this;
    }

    public function getMaxTickets(): ?int
    {
        return $this->maxTickets;
    }

    public function setMaxTickets(?int $maxTickets): self
    {
        $this->maxTickets = $maxTickets;
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
