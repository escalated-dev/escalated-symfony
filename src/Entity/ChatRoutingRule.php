<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_chat_routing_rules')]
#[ORM\Index(columns: ['is_active', 'priority'], name: 'idx_routing_active_priority')]
class ChatRoutingRule
{
    public const STRATEGY_ROUND_ROBIN = 'round_robin';
    public const STRATEGY_LEAST_ACTIVE = 'least_active';
    public const STRATEGY_DEPARTMENT = 'department';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $strategy = self::STRATEGY_ROUND_ROBIN;

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', nullable: true)]
    private ?Department $department = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $agentIds = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $priority = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $maxConcurrentChats = 5;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function setStrategy(string $strategy): self
    {
        $this->strategy = $strategy;

        return $this;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): self
    {
        $this->department = $department;

        return $this;
    }

    public function getAgentIds(): ?array
    {
        return $this->agentIds;
    }

    public function setAgentIds(?array $agentIds): self
    {
        $this->agentIds = $agentIds;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getMaxConcurrentChats(): int
    {
        return $this->maxConcurrentChats;
    }

    public function setMaxConcurrentChats(int $maxConcurrentChats): self
    {
        $this->maxConcurrentChats = $maxConcurrentChats;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
