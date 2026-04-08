<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_chat_sessions')]
#[ORM\Index(columns: ['status'], name: 'idx_chat_session_status')]
#[ORM\Index(columns: ['agent_id'], name: 'idx_chat_session_agent')]
#[ORM\HasLifecycleCallbacks]
class ChatSession
{
    public const STATUS_WAITING = 'waiting';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ENDED = 'ended';
    public const STATUS_ABANDONED = 'abandoned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: false, onDelete: 'CASCADE')]
    private Ticket $ticket;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $status = self::STATUS_WAITING;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $agentId = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $visitorUserAgent = null;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    private ?string $visitorIp = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $visitorPageUrl = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $agentJoinedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->lastActivityAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->lastActivityAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status;
    }

    public function isWaiting(): bool
    {
        return self::STATUS_WAITING === $this->status;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getAgentId(): ?int
    {
        return $this->agentId;
    }

    public function setAgentId(?int $agentId): self
    {
        $this->agentId = $agentId;

        return $this;
    }

    public function getVisitorUserAgent(): ?string
    {
        return $this->visitorUserAgent;
    }

    public function setVisitorUserAgent(?string $visitorUserAgent): self
    {
        $this->visitorUserAgent = $visitorUserAgent;

        return $this;
    }

    public function getVisitorIp(): ?string
    {
        return $this->visitorIp;
    }

    public function setVisitorIp(?string $visitorIp): self
    {
        $this->visitorIp = $visitorIp;

        return $this;
    }

    public function getVisitorPageUrl(): ?string
    {
        return $this->visitorPageUrl;
    }

    public function setVisitorPageUrl(?string $visitorPageUrl): self
    {
        $this->visitorPageUrl = $visitorPageUrl;

        return $this;
    }

    public function getAgentJoinedAt(): ?\DateTimeImmutable
    {
        return $this->agentJoinedAt;
    }

    public function setAgentJoinedAt(?\DateTimeImmutable $agentJoinedAt): self
    {
        $this->agentJoinedAt = $agentJoinedAt;

        return $this;
    }

    public function getLastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?\DateTimeImmutable $lastActivityAt): self
    {
        $this->lastActivityAt = $lastActivityAt;

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): self
    {
        $this->endedAt = $endedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
