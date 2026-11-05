<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Doctrine\UserIdType;
use Symfony\Component\Uid\Uuid;

/**
 * An in-app notification for an agent (ADR 2026-11-05-agent-panel-data,
 * section 4). `data` holds the ticket id and reference; the ticket is not a
 * column, so a ticket the agent can no longer see redacts the item instead of
 * hiding it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_notifications')]
#[ORM\Index(columns: ['user_id', 'read_at'])]
class AgentNotification
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        #[ORM\Column(name: 'user_id', type: UserIdType::NAME)]
        private int|string $userId,
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $type,
        #[ORM\Column(type: Types::JSON)]
        private array $data = [],
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::v4()->toRfc4122();
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): int|string
    {
        return $this->userId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(?\DateTimeImmutable $at = null): self
    {
        $this->readAt ??= $at ?? new \DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
