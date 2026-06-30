<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Doctrine\UserIdType;
use Escalated\Symfony\Repository\TicketFollowerRepository;

/**
 * A host user following a ticket — a notification target alongside the
 * assignee and requester. Recorded via the `add_follower` workflow action.
 * Unique per (ticket_id, user_id). See issue #67.
 */
#[ORM\Entity(repositoryClass: TicketFollowerRepository::class)]
#[ORM\Table(name: 'escalated_ticket_followers')]
#[ORM\UniqueConstraint(name: 'UNIQ_ticket_followers_ticket_user', columns: ['ticket_id', 'user_id'])]
class TicketFollower
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'ticket_id', type: Types::INTEGER)]
    private int $ticketId;

    #[ORM\Column(name: 'user_id', type: UserIdType::NAME)]
    private int|string $userId;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTicketId(): int
    {
        return $this->ticketId;
    }

    public function setTicketId(int $ticketId): self
    {
        $this->ticketId = $ticketId;

        return $this;
    }

    public function getUserId(): int|string
    {
        return $this->userId;
    }

    public function setUserId(int|string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
