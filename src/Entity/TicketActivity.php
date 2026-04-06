<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_ticket_activities')]
#[ORM\Index(columns: ['ticket_id'], name: 'idx_activity_ticket')]
class TicketActivity
{
    public const TYPE_CREATED = 'created';
    public const TYPE_STATUS_CHANGED = 'status_changed';
    public const TYPE_ASSIGNED = 'assigned';
    public const TYPE_UNASSIGNED = 'unassigned';
    public const TYPE_PRIORITY_CHANGED = 'priority_changed';
    public const TYPE_DEPARTMENT_CHANGED = 'department_changed';
    public const TYPE_REPLIED = 'replied';
    public const TYPE_NOTE_ADDED = 'note_added';
    public const TYPE_TAG_ADDED = 'tag_added';
    public const TYPE_TAG_REMOVED = 'tag_removed';
    public const TYPE_SLA_BREACHED = 'sla_breached';
    public const TYPE_ESCALATED = 'escalated';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'activities')]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: false, onDelete: 'CASCADE')]
    private Ticket $ticket;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $type;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $causerId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $causerClass = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $properties = null;

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

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function setTicket(Ticket $ticket): self
    {
        $this->ticket = $ticket;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getCauserId(): ?int
    {
        return $this->causerId;
    }

    public function setCauserId(?int $causerId): self
    {
        $this->causerId = $causerId;
        return $this;
    }

    public function getCauserClass(): ?string
    {
        return $this->causerClass;
    }

    public function setCauserClass(?string $causerClass): self
    {
        $this->causerClass = $causerClass;
        return $this;
    }

    public function getProperties(): ?array
    {
        return $this->properties;
    }

    public function setProperties(?array $properties): self
    {
        $this->properties = $properties;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
