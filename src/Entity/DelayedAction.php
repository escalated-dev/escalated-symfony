<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_delayed_actions')]
class DelayedAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $workflowId = 0;

    #[ORM\Column(type: Types::INTEGER)]
    private int $ticketId = 0;

    #[ORM\Column(type: Types::JSON)]
    private array $actionData = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $executeAt;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $executed = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->executeAt = new \DateTimeImmutable();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflowId(): int
    {
        return $this->workflowId;
    }

    public function setWorkflowId(int $workflowId): self
    {
        $this->workflowId = $workflowId;

        return $this;
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

    public function getActionData(): array
    {
        return $this->actionData;
    }

    public function setActionData(array $actionData): self
    {
        $this->actionData = $actionData;

        return $this;
    }

    public function getExecuteAt(): \DateTimeImmutable
    {
        return $this->executeAt;
    }

    public function setExecuteAt(\DateTimeImmutable $executeAt): self
    {
        $this->executeAt = $executeAt;

        return $this;
    }

    public function isExecuted(): bool
    {
        return $this->executed;
    }

    public function setExecuted(bool $executed): self
    {
        $this->executed = $executed;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
