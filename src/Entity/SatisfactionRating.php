<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A customer satisfaction (CSAT) rating left against a resolved/closed
 * ticket. One rating per ticket (enforced by a unique constraint). Mirrors
 * the Laravel SatisfactionRating model: 1-5 score, optional comment, and an
 * optional polymorphic "rated by" pointer (null for guest submissions).
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_satisfaction_ratings')]
#[ORM\UniqueConstraint(name: 'escalated_satisfaction_rating_ticket_unique', columns: ['ticket_id'])]
class SatisfactionRating
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $rating = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(name: 'rated_by_type', type: Types::STRING, length: 255, nullable: true)]
    private ?string $ratedByClass = null;

    #[ORM\Column(name: 'rated_by_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $ratedById = null;

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

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function setTicket(?Ticket $ticket): static
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function setRating(int $rating): static
    {
        $this->rating = $rating;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getRatedByClass(): ?string
    {
        return $this->ratedByClass;
    }

    public function setRatedByClass(?string $ratedByClass): static
    {
        $this->ratedByClass = $ratedByClass;

        return $this;
    }

    public function getRatedById(): ?string
    {
        return $this->ratedById;
    }

    public function setRatedById(?string $ratedById): static
    {
        $this->ratedById = $ratedById;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
