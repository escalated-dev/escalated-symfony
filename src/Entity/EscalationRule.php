<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * EscalationRule — admin-configured, time-based escalation rules.
 *
 * Matched against open tickets by EscalationService and evaluated by a
 * recurring scheduler (`escalated:escalations:run`, every 5 min by
 * convention). Mirrors the Laravel EscalationRule model.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_escalation_rules')]
#[ORM\HasLifecycleCallbacks]
class EscalationRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'trigger_type', type: Types::STRING, length: 255, nullable: true)]
    private ?string $triggerType = null;

    /** @var array<int, array{field: string, value?: mixed}> */
    #[ORM\Column(type: Types::JSON)]
    private array $conditions = [];

    /** @var array<int, array{type: string, value?: mixed}> */
    #[ORM\Column(type: Types::JSON)]
    private array $actions = [];

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    private int $sortOrder = 0;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN)]
    private bool $isActive = true;

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
    public function touchUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getTriggerType(): ?string
    {
        return $this->triggerType;
    }

    public function setTriggerType(?string $triggerType): self
    {
        $this->triggerType = $triggerType;

        return $this;
    }

    /** @return array<int, array{field: string, value?: mixed}> */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /** @param array<int, array{field: string, value?: mixed}> $conditions */
    public function setConditions(array $conditions): self
    {
        $this->conditions = $conditions;

        return $this;
    }

    /** @return array<int, array{type: string, value?: mixed}> */
    public function getActions(): array
    {
        return $this->actions;
    }

    /** @param array<int, array{type: string, value?: mixed}> $actions */
    public function setActions(array $actions): self
    {
        $this->actions = $actions;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
