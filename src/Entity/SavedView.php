<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Repository\SavedViewRepository;

#[ORM\Entity(repositoryClass: SavedViewRepository::class)]
#[ORM\Table(name: 'escalated_saved_views')]
#[ORM\Index(columns: ['user_id'], name: 'idx_saved_view_user')]
#[ORM\Index(columns: ['is_shared'], name: 'idx_saved_view_shared')]
#[ORM\HasLifecycleCallbacks]
class SavedView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $userId;

    #[ORM\Column(type: Types::JSON)]
    private array $filters = [];

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $sortBy = null;

    #[ORM\Column(type: Types::STRING, length: 4, nullable: true)]
    private ?string $sortDir = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isShared = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::INTEGER)]
    private int $position = 0;

    #[ORM\Column(type: Types::STRING, length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $icon = null;

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

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function setUserId(int $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function setFilters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    public function getSortBy(): ?string
    {
        return $this->sortBy;
    }

    public function setSortBy(?string $sortBy): self
    {
        $this->sortBy = $sortBy;

        return $this;
    }

    public function getSortDir(): ?string
    {
        return $this->sortDir;
    }

    public function setSortDir(?string $sortDir): self
    {
        $this->sortDir = $sortDir;

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

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): self
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): self
    {
        $this->color = $color;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): self
    {
        $this->icon = $icon;

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

    /**
     * Convert to filter array suitable for TicketRepository::createFilteredQueryBuilder().
     */
    public function toQueryFilters(): array
    {
        $filters = $this->filters;
        if (null !== $this->sortBy) {
            $filters['sort_by'] = $this->sortBy;
        }
        if (null !== $this->sortDir) {
            $filters['sort_dir'] = $this->sortDir;
        }

        return $filters;
    }
}
