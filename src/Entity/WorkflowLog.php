<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'escalated_workflow_logs')]
class WorkflowLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Workflow::class)]
    #[ORM\JoinColumn(name: 'workflow_id', nullable: false)]
    private ?Workflow $workflow = null;

    #[ORM\ManyToOne(targetEntity: Ticket::class)]
    #[ORM\JoinColumn(name: 'ticket_id', nullable: false)]
    private ?Ticket $ticket = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $triggerEvent = '';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $conditionsMatched = true;

    #[ORM\Column(type: Types::JSON)]
    private array $actionsExecuted = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

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

    public function getWorkflow(): ?Workflow
    {
        return $this->workflow;
    }

    public function setWorkflow(?Workflow $workflow): self
    {
        $this->workflow = $workflow;

        return $this;
    }

    public function getTicket(): ?Ticket
    {
        return $this->ticket;
    }

    public function setTicket(?Ticket $ticket): self
    {
        $this->ticket = $ticket;

        return $this;
    }

    public function getTriggerEvent(): string
    {
        return $this->triggerEvent;
    }

    public function setTriggerEvent(string $triggerEvent): self
    {
        $this->triggerEvent = $triggerEvent;

        return $this;
    }

    public function isConditionsMatched(): bool
    {
        return $this->conditionsMatched;
    }

    public function setConditionsMatched(bool $conditionsMatched): self
    {
        $this->conditionsMatched = $conditionsMatched;

        return $this;
    }

    public function getActionsExecuted(): array
    {
        return $this->actionsExecuted;
    }

    public function setActionsExecuted(array $actionsExecuted): self
    {
        $this->actionsExecuted = $actionsExecuted;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // --- Computed fields expected by the frontend ---

    /** Alias: frontend reads `event` instead of `trigger_event`. */
    public function getEvent(): string
    {
        return $this->triggerEvent;
    }

    /** Frontend reads `workflow_name` from eager-loaded relationship. */
    public function getWorkflowName(): ?string
    {
        return $this->workflow?->getName();
    }

    /** Frontend reads `ticket_reference` from eager-loaded relationship. */
    public function getTicketReference(): ?string
    {
        return $this->ticket?->getReference();
    }

    /** Boolean alias for conditions_matched. */
    public function isMatched(): bool
    {
        return $this->conditionsMatched;
    }

    /** Integer count of executed actions. */
    public function getActionsExecutedCount(): int
    {
        return \count($this->actionsExecuted);
    }

    /** Raw actions array for the expanded detail view. */
    public function getActionDetails(): array
    {
        return $this->actionsExecuted;
    }

    /** Milliseconds between started_at and completed_at. */
    public function getDurationMs(): ?int
    {
        if ($this->startedAt !== null && $this->completedAt !== null) {
            $diff = $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
            $microDiff = ((int) $this->completedAt->format('u')) - ((int) $this->startedAt->format('u'));

            return ($diff * 1000) + (int) round($microDiff / 1000);
        }

        return null;
    }

    /** Computed status: 'failed' when an error is present, otherwise 'success'. */
    public function getComputedStatus(): string
    {
        return ($this->errorMessage !== null && $this->errorMessage !== '') ? 'failed' : 'success';
    }

    /**
     * Serialize log with computed fields for frontend.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow?->getId(),
            'ticket_id' => $this->ticket?->getId(),
            'trigger_event' => $this->triggerEvent,
            'event' => $this->getEvent(),
            'workflow_name' => $this->getWorkflowName(),
            'ticket_reference' => $this->getTicketReference(),
            'matched' => $this->isMatched(),
            'actions_executed' => $this->getActionsExecutedCount(),
            'action_details' => $this->getActionDetails(),
            'duration_ms' => $this->getDurationMs(),
            'status' => $this->getComputedStatus(),
            'error_message' => $this->errorMessage,
            'created_at' => $this->createdAt->format('c'),
        ];
    }
}
