<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Repository\TicketRepository;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
#[ORM\Table(name: 'escalated_tickets')]
#[ORM\Index(columns: ['status'], name: 'idx_ticket_status')]
#[ORM\Index(columns: ['priority'], name: 'idx_ticket_priority')]
#[ORM\Index(columns: ['assigned_to'], name: 'idx_ticket_assigned')]
#[ORM\Index(columns: ['requester_id'], name: 'idx_ticket_requester')]
#[ORM\HasLifecycleCallbacks]
class Ticket
{
    public const TYPES = ['question', 'problem', 'incident', 'task'];

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING_ON_CUSTOMER = 'waiting_on_customer';
    public const STATUS_WAITING_ON_AGENT = 'waiting_on_agent';
    public const STATUS_ESCALATED = 'escalated';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_REOPENED = 'reopened';
    public const STATUS_SNOOZED = 'snoozed';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';
    public const PRIORITY_CRITICAL = 'critical';

    /**
     * Valid status transitions.
     */
    public const TRANSITIONS = [
        self::STATUS_OPEN => [self::STATUS_SNOOZED, self::STATUS_IN_PROGRESS, self::STATUS_WAITING_ON_CUSTOMER, self::STATUS_WAITING_ON_AGENT, self::STATUS_ESCALATED, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_IN_PROGRESS => [self::STATUS_SNOOZED, self::STATUS_WAITING_ON_CUSTOMER, self::STATUS_WAITING_ON_AGENT, self::STATUS_ESCALATED, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_WAITING_ON_CUSTOMER => [self::STATUS_SNOOZED, self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_WAITING_ON_AGENT => [self::STATUS_SNOOZED, self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_ESCALATED, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_ESCALATED => [self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_RESOLVED => [self::STATUS_REOPENED, self::STATUS_CLOSED],
        self::STATUS_CLOSED => [self::STATUS_REOPENED],
        self::STATUS_REOPENED => [self::STATUS_IN_PROGRESS, self::STATUS_WAITING_ON_CUSTOMER, self::STATUS_WAITING_ON_AGENT, self::STATUS_ESCALATED, self::STATUS_RESOLVED, self::STATUS_CLOSED],
        self::STATUS_SNOOZED => [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_WAITING_ON_CUSTOMER, self::STATUS_WAITING_ON_AGENT, self::STATUS_ESCALATED, self::STATUS_RESOLVED, self::STATUS_CLOSED],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 32, unique: true)]
    private string $reference = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $subject = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $priority = self::PRIORITY_MEDIUM;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $ticketType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $requesterId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $requesterClass = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $assignedTo = null;

    #[ORM\ManyToOne(targetEntity: Department::class, inversedBy: 'tickets')]
    #[ORM\JoinColumn(name: 'department_id', nullable: true)]
    private ?Department $department = null;

    #[ORM\ManyToOne(targetEntity: SlaPolicy::class)]
    #[ORM\JoinColumn(name: 'sla_policy_id', nullable: true)]
    private ?SlaPolicy $slaPolicy = null;

    /** @var Collection<int, Reply> */
    #[ORM\OneToMany(targetEntity: Reply::class, mappedBy: 'ticket', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $replies;

    /** @var Collection<int, Tag> */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'tickets')]
    #[ORM\JoinTable(name: 'escalated_ticket_tag')]
    private Collection $tags;

    /** @var Collection<int, TicketActivity> */
    #[ORM\OneToMany(targetEntity: TicketActivity::class, mappedBy: 'ticket', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $activities;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $guestName = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $guestEmail = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $guestToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstResponseAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $firstResponseDueAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolutionDueAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $slaFirstResponseBreached = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $slaResolutionBreached = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORMColumn(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $snoozedUntil = null;

    #[ORMColumn(type: Types::INTEGER, nullable: true)]
    private ?int $snoozedBy = null;

    #[ORMColumn(type: Types::STRING, length: 32, nullable: true)]
    private ?string $statusBeforeSnooze = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->replies = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->activities = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ('' === $this->reference) {
            // Temporary reference; should be updated after flush with generateReference()
            $this->reference = 'TEMP-'.Uuid::v4()->toRfc4122();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function generateReference(string $prefix = 'ESC'): string
    {
        return sprintf('%s-%05d', $prefix, $this->id);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    public function isOpen(): bool
    {
        return !in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    public function isSnoozed(): bool
    {
        return self::STATUS_SNOOZED === $this->status && null !== $this->snoozedUntil;
    }

    public function isGuest(): bool
    {
        return null === $this->requesterClass && null !== $this->guestToken;
    }

    // --- Getters and Setters ---

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function getTicketType(): ?string
    {
        return $this->ticketType;
    }

    public function setTicketType(?string $ticketType): self
    {
        $this->ticketType = $ticketType;

        return $this;
    }

    public function getRequesterId(): ?int
    {
        return $this->requesterId;
    }

    public function setRequesterId(?int $requesterId): self
    {
        $this->requesterId = $requesterId;

        return $this;
    }

    public function getRequesterClass(): ?string
    {
        return $this->requesterClass;
    }

    public function setRequesterClass(?string $requesterClass): self
    {
        $this->requesterClass = $requesterClass;

        return $this;
    }

    public function getAssignedTo(): ?int
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?int $assignedTo): self
    {
        $this->assignedTo = $assignedTo;

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

    public function getSlaPolicy(): ?SlaPolicy
    {
        return $this->slaPolicy;
    }

    public function setSlaPolicy(?SlaPolicy $slaPolicy): self
    {
        $this->slaPolicy = $slaPolicy;

        return $this;
    }

    /** @return Collection<int, Reply> */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function addReply(Reply $reply): self
    {
        if (!$this->replies->contains($reply)) {
            $this->replies->add($reply);
            $reply->setTicket($this);
        }

        return $this;
    }

    /** @return Collection<int, Reply> */
    public function getPublicReplies(): Collection
    {
        return $this->replies->filter(fn (Reply $r) => !$r->isInternalNote());
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): self
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
        }

        return $this;
    }

    public function removeTag(Tag $tag): self
    {
        $this->tags->removeElement($tag);

        return $this;
    }

    /** @return Collection<int, TicketActivity> */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function addActivity(TicketActivity $activity): self
    {
        if (!$this->activities->contains($activity)) {
            $this->activities->add($activity);
            $activity->setTicket($this);
        }

        return $this;
    }

    public function getGuestName(): ?string
    {
        return $this->guestName;
    }

    public function setGuestName(?string $guestName): self
    {
        $this->guestName = $guestName;

        return $this;
    }

    public function getGuestEmail(): ?string
    {
        return $this->guestEmail;
    }

    public function setGuestEmail(?string $guestEmail): self
    {
        $this->guestEmail = $guestEmail;

        return $this;
    }

    public function getGuestToken(): ?string
    {
        return $this->guestToken;
    }

    public function setGuestToken(?string $guestToken): self
    {
        $this->guestToken = $guestToken;

        return $this;
    }

    public function getFirstResponseAt(): ?\DateTimeImmutable
    {
        return $this->firstResponseAt;
    }

    public function setFirstResponseAt(?\DateTimeImmutable $firstResponseAt): self
    {
        $this->firstResponseAt = $firstResponseAt;

        return $this;
    }

    public function getFirstResponseDueAt(): ?\DateTimeImmutable
    {
        return $this->firstResponseDueAt;
    }

    public function setFirstResponseDueAt(?\DateTimeImmutable $firstResponseDueAt): self
    {
        $this->firstResponseDueAt = $firstResponseDueAt;

        return $this;
    }

    public function getResolutionDueAt(): ?\DateTimeImmutable
    {
        return $this->resolutionDueAt;
    }

    public function setResolutionDueAt(?\DateTimeImmutable $resolutionDueAt): self
    {
        $this->resolutionDueAt = $resolutionDueAt;

        return $this;
    }

    public function isSlaFirstResponseBreached(): bool
    {
        return $this->slaFirstResponseBreached;
    }

    public function setSlaFirstResponseBreached(bool $breached): self
    {
        $this->slaFirstResponseBreached = $breached;

        return $this;
    }

    public function isSlaResolutionBreached(): bool
    {
        return $this->slaResolutionBreached;
    }

    public function setSlaResolutionBreached(bool $breached): self
    {
        $this->slaResolutionBreached = $breached;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): self
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

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

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    public function getSnoozedUntil(): ?\DateTimeImmutable
    {
        return $this->snoozedUntil;
    }

    public function setSnoozedUntil(?\DateTimeImmutable $snoozedUntil): self
    {
        $this->snoozedUntil = $snoozedUntil;

        return $this;
    }

    public function getSnoozedBy(): ?int
    {
        return $this->snoozedBy;
    }

    public function setSnoozedBy(?int $snoozedBy): self
    {
        $this->snoozedBy = $snoozedBy;

        return $this;
    }

    public function getStatusBeforeSnooze(): ?string
    {
        return $this->statusBeforeSnooze;
    }

    public function setStatusBeforeSnooze(?string $statusBeforeSnooze): self
    {
        $this->statusBeforeSnooze = $statusBeforeSnooze;

        return $this;
    }
}
