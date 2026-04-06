<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_replies')]
#[ORM\Index(columns: ['ticket_id'], name: 'idx_reply_ticket')]
#[ORM\HasLifecycleCallbacks]
class Reply
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: false, onDelete: 'CASCADE')]
    private Ticket $ticket;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $authorId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $authorClass = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isInternalNote = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isPinned = false;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $type = 'reply';

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
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // --- Getters and Setters ---

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

    public function getAuthorId(): ?int
    {
        return $this->authorId;
    }

    public function setAuthorId(?int $authorId): self
    {
        $this->authorId = $authorId;
        return $this;
    }

    public function getAuthorClass(): ?string
    {
        return $this->authorClass;
    }

    public function setAuthorClass(?string $authorClass): self
    {
        $this->authorClass = $authorClass;
        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function isInternalNote(): bool
    {
        return $this->isInternalNote;
    }

    public function setIsInternalNote(bool $isInternalNote): self
    {
        $this->isInternalNote = $isInternalNote;
        return $this;
    }

    public function isPinned(): bool
    {
        return $this->isPinned;
    }

    public function setIsPinned(bool $isPinned): self
    {
        $this->isPinned = $isPinned;
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
}
