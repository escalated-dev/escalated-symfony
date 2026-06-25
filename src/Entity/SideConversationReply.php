<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A single message within a {@see SideConversation}. Mirrors the Laravel
 * SideConversationReply model.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_side_conversation_replies')]
#[ORM\Index(columns: ['side_conversation_id'], name: 'idx_side_conversation_reply_conversation')]
#[ORM\HasLifecycleCallbacks]
class SideConversationReply
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SideConversation::class, inversedBy: 'replies')]
    #[ORM\JoinColumn(name: 'side_conversation_id', nullable: false, onDelete: 'CASCADE')]
    private ?SideConversation $sideConversation = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $body = '';

    #[ORM\Column(name: 'author_id', type: Types::STRING, length: 255, nullable: true)]
    private ?string $authorId = null;

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

    public function getSideConversation(): ?SideConversation
    {
        return $this->sideConversation;
    }

    public function setSideConversation(?SideConversation $sideConversation): static
    {
        $this->sideConversation = $sideConversation;

        return $this;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): static
    {
        $this->body = $body;

        return $this;
    }

    public function getAuthorId(): ?string
    {
        return $this->authorId;
    }

    public function setAuthorId(?string $authorId): static
    {
        $this->authorId = $authorId;

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
