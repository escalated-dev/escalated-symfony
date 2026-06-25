<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A directional relationship between two tickets (problem/incident,
 * parent/child, or related). Distinct from {@see TicketSubjectLink}, which
 * links a ticket to a host-app subject. Mirrors the Laravel TicketLink model.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_ticket_links')]
#[ORM\Index(columns: ['parent_ticket_id'], name: 'idx_ticket_link_parent')]
#[ORM\Index(columns: ['child_ticket_id'], name: 'idx_ticket_link_child')]
class TicketLink
{
    public const TYPE_PROBLEM_INCIDENT = 'problem_incident';
    public const TYPE_PARENT_CHILD = 'parent_child';
    public const TYPE_RELATED = 'related';

    public const TYPES = [
        self::TYPE_PROBLEM_INCIDENT,
        self::TYPE_PARENT_CHILD,
        self::TYPE_RELATED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'parent_ticket_id', nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $parentTicket = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'child_ticket_id', nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $childTicket = null;

    #[ORM\Column(name: 'link_type', type: Types::STRING, length: 32)]
    private string $linkType = self::TYPE_RELATED;

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

    public function getParentTicket(): ?Ticket
    {
        return $this->parentTicket;
    }

    public function setParentTicket(?Ticket $parentTicket): static
    {
        $this->parentTicket = $parentTicket;

        return $this;
    }

    public function getChildTicket(): ?Ticket
    {
        return $this->childTicket;
    }

    public function setChildTicket(?Ticket $childTicket): static
    {
        $this->childTicket = $childTicket;

        return $this;
    }

    public function getLinkType(): string
    {
        return $this->linkType;
    }

    public function setLinkType(string $linkType): static
    {
        $this->linkType = $linkType;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
