<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;

class WorkflowEngine
{
    public const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with',
        'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal', 'is_empty', 'is_not_empty',
    ];

    public const ACTION_TYPES = [
        'change_status', 'assign_agent', 'change_priority', 'add_tag', 'remove_tag',
        'set_department', 'add_note', 'send_webhook', 'set_type', 'delay',
        'add_follower', 'send_notification',
    ];

    public const TRIGGER_EVENTS = [
        'ticket.created', 'ticket.updated', 'ticket.status_changed', 'ticket.assigned',
        'ticket.priority_changed', 'ticket.tagged', 'ticket.department_changed',
        'reply.created', 'reply.agent_reply', 'sla.warning', 'sla.breached', 'ticket.reopened',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function processEvent(string $eventName, Ticket $ticket): void
    {
        $workflows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM escalated_workflows WHERE trigger_event = ? AND is_active = 1 ORDER BY position ASC',
            [$eventName]
        );
        foreach ($workflows as $workflow) {
            $this->processWorkflow($workflow, $ticket, $eventName);
        }
    }

    public function evaluateConditions(array $conditions, Ticket $ticket): bool
    {
        if (isset($conditions['all'])) {
            foreach ($conditions['all'] as $c) {
                if (!$this->evalSingle($c, $ticket)) {
                    return false;
                }
            }
            return true;
        }
        if (isset($conditions['any'])) {
            foreach ($conditions['any'] as $c) {
                if ($this->evalSingle($c, $ticket)) {
                    return true;
                }
            }
            return false;
        }
        if (isset($conditions['field'])) {
            return $this->evalSingle($conditions, $ticket);
        }
        if (array_is_list($conditions)) {
            foreach ($conditions as $c) {
                if (!$this->evalSingle($c, $ticket)) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    public function dryRun(array $workflow, Ticket $ticket): array
    {
        $conditions = \is_string($workflow['conditions']) ? json_decode($workflow['conditions'], true) : $workflow['conditions'];
        $actions = \is_string($workflow['actions']) ? json_decode($workflow['actions'], true) : $workflow['actions'];
        $matched = $this->evaluateConditions($conditions ?? [], $ticket);
        $preview = array_map(fn ($a) => [
            'type' => $a['type'],
            'value' => $this->interpolate((string) ($a['value'] ?? ''), $ticket),
            'would_execute' => $matched,
        ], $actions ?? []);
        return ['matched' => $matched, 'actions' => $preview];
    }

    public function processDelayedActions(): void
    {
        $pending = $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM escalated_delayed_actions WHERE executed = 0 AND execute_at <= ?',
            [(new \DateTime())->format('Y-m-d H:i:s')]
        );
        foreach ($pending as $delayed) {
            try {
                $ticket = $this->em->find(Ticket::class, $delayed['ticket_id']);
                if (!$ticket) {
                    continue;
                }
                $actionData = \is_string($delayed['action_data']) ? json_decode($delayed['action_data'], true) : $delayed['action_data'];
                $this->executeSingleAction($actionData, $ticket, (int) $delayed['workflow_id']);
                $this->em->getConnection()->executeStatement(
                    'UPDATE escalated_delayed_actions SET executed = 1 WHERE id = ?',
                    [$delayed['id']]
                );
            } catch (\Throwable $e) {
                // Log and continue
            }
        }
    }

    private function processWorkflow(array $workflow, Ticket $ticket, string $eventName): void
    {
        $conditions = \is_string($workflow['conditions']) ? json_decode($workflow['conditions'], true) : $workflow['conditions'];
        $matched = $this->evaluateConditions($conditions ?? [], $ticket);
        if (!$matched) {
            $this->logExecution((int) $workflow['id'], $ticket->getId(), $eventName, 'skipped', []);
            return;
        }
        try {
            $actions = \is_string($workflow['actions']) ? json_decode($workflow['actions'], true) : $workflow['actions'];
            $executed = $this->executeActions($actions ?? [], $ticket, (int) $workflow['id']);
            $this->logExecution((int) $workflow['id'], $ticket->getId(), $eventName, 'success', $executed);
        } catch (\Throwable $e) {
            $this->logExecution((int) $workflow['id'], $ticket->getId(), $eventName, 'failure', [], $e->getMessage());
        }
    }

    private function evalSingle(array $condition, Ticket $ticket): bool
    {
        $field = $condition['field'] ?? '';
        $operator = $condition['operator'] ?? 'equals';
        $expected = $condition['value'] ?? '';
        $actual = $this->resolveField($field, $ticket);
        return $this->applyOperator($operator, $actual, $expected);
    }

    private function resolveField(string $field, Ticket $ticket): mixed
    {
        return match ($field) {
            'status' => $ticket->getStatus(),
            'priority' => $ticket->getPriority(),
            'subject' => $ticket->getSubject(),
            'description' => $ticket->getDescription(),
            'assigned_to' => $ticket->getAssignedTo(),
            'department_id' => $ticket->getDepartment()?->getId(),
            default => null,
        };
    }

    private function applyOperator(string $operator, mixed $actual, mixed $expected): bool
    {
        $actualS = (string) ($actual ?? '');
        $expectedS = (string) ($expected ?? '');
        return match ($operator) {
            'equals' => $actualS === $expectedS,
            'not_equals' => $actualS !== $expectedS,
            'contains' => str_contains($actualS, $expectedS),
            'not_contains' => !str_contains($actualS, $expectedS),
            'starts_with' => str_starts_with($actualS, $expectedS),
            'ends_with' => str_ends_with($actualS, $expectedS),
            'greater_than' => (float) $actual > (float) $expected,
            'less_than' => (float) $actual < (float) $expected,
            'greater_or_equal' => (float) $actual >= (float) $expected,
            'less_or_equal' => (float) $actual <= (float) $expected,
            'is_empty' => '' === trim($actualS),
            'is_not_empty' => '' !== trim($actualS),
            default => false,
        };
    }

    private function executeActions(array $actions, Ticket $ticket, int $workflowId): array
    {
        $executed = [];
        foreach ($actions as $action) {
            $result = $this->executeSingleAction($action, $ticket, $workflowId);
            $executed[] = ['type' => $action['type'], 'result' => $result];
        }
        return $executed;
    }

    private function executeSingleAction(array $action, Ticket $ticket, int $workflowId): string
    {
        try {
            match ($action['type']) {
                'change_status' => $ticket->setStatus($action['value']) && $this->em->flush(),
                'assign_agent' => $ticket->setAssignedTo((int) $action['value']) && $this->em->flush(),
                'change_priority' => $ticket->setPriority($action['value']) && $this->em->flush(),
                'add_note' => $this->addNote($ticket, $this->interpolate((string) ($action['value'] ?? ''), $ticket)),
                'set_type' => $ticket->setTicketType($action['value']) && $this->em->flush(),
                default => null,
            };
            return 'executed';
        } catch (\Throwable $e) {
            return 'failed';
        }
    }

    private function addNote(Ticket $ticket, string $body): void
    {
        $reply = new Reply();
        $reply->setTicket($ticket);
        $reply->setBody($body);
        $reply->setIsInternalNote(true);
        $this->em->persist($reply);
        $this->em->flush();
    }

    private function interpolate(string $text, Ticket $ticket): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => match ($m[1]) {
            'ticket_id' => (string) $ticket->getId(),
            'reference' => $ticket->getReference(),
            'subject' => $ticket->getSubject(),
            'status' => $ticket->getStatus(),
            'priority' => $ticket->getPriority(),
            default => $m[0],
        }, $text);
    }

    private function logExecution(int $workflowId, int $ticketId, string $event, string $status, array $actions, ?string $error = null): void
    {
        $this->em->getConnection()->insert('escalated_workflow_logs', [
            'workflow_id' => $workflowId,
            'ticket_id' => $ticketId,
            'trigger_event' => $event,
            'status' => $status,
            'actions_executed' => json_encode($actions),
            'error_message' => $error,
            'created_at' => (new \DateTime())->format('Y-m-d H:i:s'),
        ]);
    }
}
