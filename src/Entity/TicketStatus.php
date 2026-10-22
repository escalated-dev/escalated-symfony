<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Repository\TicketStatusRepository;

/**
 * A ticket status an admin defines (escalated-laravel's TicketStatus), as a
 * sub-status of one of five categories. All lifecycle logic keys off the
 * category: giving a ticket a custom status moves its built-in status to the
 * category's anchor ({@see self::ANCHORS}), so SLA, resolved_at, events and
 * workflows behave exactly as they do for that built-in status.
 *
 * The built-in statuses are listed as `is_system` rows so the admin page shows
 * them; their slug and category are fixed. Lifecycle code never reads those
 * rows: a ticket's built-in `status` column stays the source of truth.
 */
#[ORM\Entity(repositoryClass: TicketStatusRepository::class)]
#[ORM\Table(name: 'escalated_ticket_statuses')]
#[ORM\UniqueConstraint(name: 'uniq_ticket_status_slug', columns: ['slug'])]
#[ORM\HasLifecycleCallbacks]
class TicketStatus
{
    public const CATEGORY_NEW = 'new';
    public const CATEGORY_OPEN = 'open';
    public const CATEGORY_PENDING = 'pending';
    public const CATEGORY_ON_HOLD = 'on_hold';
    public const CATEGORY_SOLVED = 'solved';

    /** Laravel's list, in its order. */
    public const CATEGORIES = [self::CATEGORY_NEW, self::CATEGORY_OPEN, self::CATEGORY_PENDING, self::CATEGORY_ON_HOLD, self::CATEGORY_SOLVED];

    /** The built-in status a ticket takes when it gets a custom status of the category. */
    public const ANCHORS = [
        self::CATEGORY_NEW => Ticket::STATUS_OPEN,
        self::CATEGORY_OPEN => Ticket::STATUS_OPEN,
        self::CATEGORY_PENDING => Ticket::STATUS_WAITING_ON_CUSTOMER,
        self::CATEGORY_ON_HOLD => Ticket::STATUS_IN_PROGRESS,
        self::CATEGORY_SOLVED => Ticket::STATUS_RESOLVED,
    ];

    public const DEFAULT_COLOR = '#6b7280';

    /**
     * The built-in statuses as system rows: slug => [label, colour, category].
     */
    public const SYSTEM = [
        Ticket::STATUS_OPEN => ['Open', '#3B82F6', self::CATEGORY_OPEN],
        Ticket::STATUS_IN_PROGRESS => ['In Progress', '#8B5CF6', self::CATEGORY_OPEN],
        Ticket::STATUS_WAITING_ON_CUSTOMER => ['Waiting on Customer', '#F59E0B', self::CATEGORY_PENDING],
        Ticket::STATUS_WAITING_ON_AGENT => ['Waiting on Agent', '#F97316', self::CATEGORY_OPEN],
        Ticket::STATUS_ESCALATED => ['Escalated', '#EF4444', self::CATEGORY_OPEN],
        Ticket::STATUS_RESOLVED => ['Resolved', '#10B981', self::CATEGORY_SOLVED],
        Ticket::STATUS_CLOSED => ['Closed', '#6B7280', self::CATEGORY_SOLVED],
        Ticket::STATUS_REOPENED => ['Reopened', '#3B82F6', self::CATEGORY_OPEN],
        Ticket::STATUS_SNOOZED => ['Snoozed', '#64748B', self::CATEGORY_ON_HOLD],
        Ticket::STATUS_LIVE => ['Live', '#06B6D4', self::CATEGORY_OPEN],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $label = '';

    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $slug = '';

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $category = self::CATEGORY_OPEN;

    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => self::DEFAULT_COLOR])]
    private string $color = self::DEFAULT_COLOR;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSystem = false;

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
     * The category of a built-in status: the statuses spec's mapping.
     */
    public static function categoryOfBuiltIn(string $status): string
    {
        return self::SYSTEM[$status][2] ?? self::CATEGORY_OPEN;
    }

    /**
     * The built-in status a custom status of this category sets.
     */
    public function anchor(): string
    {
        return self::ANCHORS[$this->category] ?? Ticket::STATUS_OPEN;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        if (!\in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown status category "%s".', $category));
        }
        $this->category = $category;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

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

    public function isSystem(): bool
    {
        return $this->isSystem;
    }

    public function setIsSystem(bool $isSystem): self
    {
        $this->isSystem = $isSystem;

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
     * The shape tickets carry as `custom_status`.
     *
     * @return array{id: int|null, label: string, slug: string, color: string, category: string}
     */
    public function summary(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'slug' => $this->slug, 'color' => $this->color, 'category' => $this->category];
    }
}
