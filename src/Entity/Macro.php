<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Macro — agent-applied, manual one-click action bundle.
 *
 * Distinct from Workflow (admin event-driven) and Automation (admin
 * time-based). See escalated-developer-context/domain-model/
 * workflows-automations-macros.md.
 *
 * Agent picks a macro on a specific ticket and clicks "apply." All
 * actions in the bundle execute at once. No conditions; no triggers.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_macros')]
#[ORM\HasLifecycleCallbacks]
class Macro
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var array<int, array{type: string, value?: mixed}> */
    #[ORM\Column(type: Types::JSON)]
    private array $actions = [];

    /**
     * If true, all agents see and can apply this macro.
     * If false, only the creator (createdBy) sees it.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isShared = true;

    /**
     * Host-app user id of the agent who created this macro.
     * Null only for system-seeded macros.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $createdBy = null;

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

    public function getActions(): array
    {
        return $this->actions;
    }

    public function setActions(array $actions): self
    {
        $this->actions = $actions;

        return $this;
    }

    public function isShared(): bool
    {
        return $this->isShared;
    }

    public function setIsShared(bool $isShared): self
    {
        $this->isShared = $isShared;

        return $this;
    }

    public function getCreatedBy(): ?int
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?int $createdBy): self
    {
        $this->createdBy = $createdBy;

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
