<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A side conversation — a private thread (internal note or outbound email)
 * attached to a ticket, used by agents to consult colleagues or third
 * parties without exposing the main customer thread. Mirrors the Laravel
 * SideConversation model.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_side_conversations')]
#[ORM\Index(columns: ['ticket_id'], name: 'idx_side_conversation_ticket')]
#[ORM\HasLifecycleCallbacks]
class SideConversation
{
    public const CHANNEL_INTERNAL = 'internal';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNELS = [self::CHANNEL_INTERNAL, self::CHANNEL_EMAIL];

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: false, onDelete: 'CASCADE')]
    private ?Ticket $ticket = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $subject = '';

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $channel = self::CHANNEL_INTERNAL;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'created_by', type: Types::STRING, length: 255, nullable: true)]
    private ?string $createdBy = null;

    /** @var Collection<int, SideConversationReply> */
    #[ORM\OneToMany(mappedBy: 'sideConversation', targetEntity: SideConversationReply::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $replies;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->replies = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isOpen(): bool
    {
        return self::STATUS_OPEN === $this->status;
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

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /** @return Collection<int, SideConversationReply> */
    public function getReplies(): Collection
    {
        return $this->replies;
    }

    public function addReply(SideConversationReply $reply): static
    {
        if (!$this->replies->contains($reply)) {
            $this->replies->add($reply);
            $reply->setSideConversation($this);
        }

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
