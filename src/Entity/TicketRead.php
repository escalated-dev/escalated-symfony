<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Doctrine\UserIdType;

/**
 * When an agent last read a ticket (ADR 2026-11-05-agent-panel-data).
 * `lastReadAt` null means the agent marked the ticket unread. Written and
 * read through {@see \Escalated\Symfony\Service\AgentPanel}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_ticket_reads')]
#[ORM\UniqueConstraint(columns: ['user_id', 'ticket_id'])]
#[ORM\Index(columns: ['ticket_id'])]
class TicketRead
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(name: 'user_id', type: UserIdType::NAME)]
        private int|string $userId,
        #[ORM\ManyToOne(targetEntity: Ticket::class)]
        #[ORM\JoinColumn(name: 'ticket_id', nullable: false, onDelete: 'CASCADE')]
        private Ticket $ticket,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $lastReadAt = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int|string
    {
        return $this->userId;
    }

    public function getTicket(): Ticket
    {
        return $this->ticket;
    }

    public function getLastReadAt(): ?\DateTimeImmutable
    {
        return $this->lastReadAt;
    }

    public function setLastReadAt(?\DateTimeImmutable $at): self
    {
        $this->lastReadAt = $at;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
