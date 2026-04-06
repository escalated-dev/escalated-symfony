<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_sla_policies')]
#[ORM\HasLifecycleCallbacks]
class SlaPolicy
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * First response hours per priority level.
     * Example: {"low": 24, "medium": 8, "high": 4, "urgent": 1, "critical": 0.5}
     */
    #[ORM\Column(type: Types::JSON)]
    private array $firstResponseHours = [];

    /**
     * Resolution hours per priority level.
     * Example: {"low": 72, "medium": 24, "high": 8, "urgent": 4, "critical": 2}
     */
    #[ORM\Column(type: Types::JSON)]
    private array $resolutionHours = [];

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $businessHoursOnly = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::BOOLEAN)]
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
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFirstResponseHoursFor(string $priority): ?float
    {
        return $this->firstResponseHours[$priority] ?? null;
    }

    public function getResolutionHoursFor(string $priority): ?float
    {
        return $this->resolutionHours[$priority] ?? null;
    }

    // --- Getters and Setters ---

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

    public function getFirstResponseHours(): array
    {
        return $this->firstResponseHours;
    }

    public function setFirstResponseHours(array $firstResponseHours): self
    {
        $this->firstResponseHours = $firstResponseHours;
        return $this;
    }

    public function getResolutionHours(): array
    {
        return $this->resolutionHours;
    }

    public function setResolutionHours(array $resolutionHours): self
    {
        $this->resolutionHours = $resolutionHours;
        return $this;
    }

    public function isBusinessHoursOnly(): bool
    {
        return $this->businessHoursOnly;
    }

    public function setBusinessHoursOnly(bool $businessHoursOnly): self
    {
        $this->businessHoursOnly = $businessHoursOnly;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;
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
