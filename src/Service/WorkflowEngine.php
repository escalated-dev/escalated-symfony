<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\DelayedAction;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketFollower;
use Escalated\Symfony\Event\EventNames;
use Escalated\Symfony\Security\UserKeyResolver;

class WorkflowEngine
{
    public const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with',
        'greater_than', 'less_than', 'greater_or_equal', 'less_or_equal', 'is_empty', 'is_not_empty',
    ];

    /**
     * The action types offered in the builder: the contract's core catalog
     * plus the optional actions this engine runs. `send_webhook` is not run,
     * so it is not offered.
     */
    public const OFFERED_ACTIONS = [
        'change_status', 'change_priority', 'add_tag', 'remove_tag', 'set_department',
        'assign_agent', 'add_note', 'insert_canned_reply',
        'add_follower', 'delay',
    ];

    /**
     * Every action type this engine runs: the offered ones, `set_type`, and
     * the Laravel aliases `move_department` and `add_internal_note`.
     */
    public const HANDLED_ACTIONS = [
        ...self::OFFERED_ACTIONS,
        'set_type', 'move_department', 'add_internal_note',
    ];

    /**
     * @deprecated use {@see OFFERED_ACTIONS} or {@see HANDLED_ACTIONS}. This
     *             list included `send_webhook` and `send_notification`, which
     *             the engine never ran.
     */
    public const ACTION_TYPES = self::HANDLED_ACTIONS;

    /**
     * The events a workflow can be triggered by: the canonical names this
     * backend actually emits. A workflow saved against anything else never
     * runs. Legacy names on stored workflows (`ticket.replied`,
     * `ticket.tagged`, ...) still fire; see {@see EventNames::LEGACY}.
     */
    public const TRIGGER_EVENTS = EventNames::EMITTED;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $tablePrefix = 'escalated_',
        private readonly ?\Escalated\Symfony\Security\TicketAccess $access = null,
    ) {
    }

    public function processEvent(string $eventName, Ticket $ticket): void
    {
        $this->access?->assertVisible($ticket);
        $triggers = EventNames::aliases($eventName);
        $placeholders = implode(', ', array_fill(0, \count($triggers), '?'));

        $workflows = $this->em->getConnection()->fetchAllAssociative(
            $this->sql('SELECT * FROM escalated_workflows WHERE trigger_event IN ('.$placeholders.') AND is_active = ? ORDER BY position ASC'),
            [...$triggers, true],
            [...array_fill(0, \count($triggers), Types::STRING), Types::BOOLEAN],
        );
        foreach ($workflows as $workflow) {
            $this->processWorkflow($workflow, $ticket, $eventName);
        }
    }

    public function evaluateConditions(array $conditions, Ticket $ticket): bool
    {
        $this->access?->assertVisible($ticket);
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
        $this->access?->assertVisible($ticket);
        $conditions = \is_string($workflow['conditions']) ? json_decode($workflow['conditions'], true) : $workflow['conditions'];
        $actions = \is_string($workflow['actions']) ? json_decode($workflow['actions'], true) : $workflow['actions'];
        $matched = is_array($conditions) && $this->evaluateConditions($conditions, $ticket);
        $preview = array_map(fn ($a) => [
            'type' => $a['type'],
            'value' => $this->interpolate((string) ($a['value'] ?? ''), $ticket),
            'would_execute' => $matched,
        ], $actions ?? []);

        return ['matched' => $matched, 'actions' => $preview];
    }

    public function processDelayedActions(): void
    {
        // Booleans are bound with their DBAL type: PostgreSQL rejects comparing a
        // boolean column to an integer literal.
        $pending = $this->em->getConnection()->fetchAllAssociative(
            $this->sql('SELECT * FROM escalated_delayed_actions WHERE executed = ? AND execute_at <= ?'),
            [false, new \DateTimeImmutable()],
            [Types::BOOLEAN, Types::DATETIME_IMMUTABLE],
        );
        foreach ($pending as $delayed) {
            try {
                $ticket = $this->em->getRepository(Ticket::class)->find($delayed['ticket_id']);
                if (!$ticket) {
                    continue;
                }
                $actionData = \is_string($delayed['action_data']) ? json_decode($delayed['action_data'], true) : $delayed['action_data'];
                $this->executeSingleAction($actionData, $ticket, (int) $delayed['workflow_id']);
                $this->em->getConnection()->executeStatement(
                    $this->sql('UPDATE escalated_delayed_actions SET executed = ? WHERE id = ?'),
                    [true, $delayed['id']],
                    [Types::BOOLEAN, Types::INTEGER],
                );
            } catch (\Throwable $e) {
                // Log and continue
            }
        }
    }

    private function processWorkflow(array $workflow, Ticket $ticket, string $eventName): void
    {
        $conditions = \is_string($workflow['conditions']) ? json_decode($workflow['conditions'], true) : $workflow['conditions'];
        $matched = is_array($conditions) && $this->evaluateConditions($conditions, $ticket);
        if (!$matched) {
            $this->logExecution((int) $workflow['id'], $ticket->getId(), $eventName, false, []);

            return;
        }
        $startedAt = new \DateTimeImmutable();
        try {
            $actions = \is_string($workflow['actions']) ? json_decode($workflow['actions'], true) : $workflow['actions'];
            $executed = $this->executeActions($actions ?? [], $ticket, (int) $workflow['id']);
            $this->logExecution((int) $workflow['id'], $ticket->getId(), $eventName, true, $executed, null, $startedAt);
        } catch (\Throwable $e) {
            $this->logExecution((int) $workflow['id'], $ticket->getId(), $eventName, true, [], $e->getMessage(), $startedAt);
        }
    }

    private function evalSingle(array $condition, Ticket $ticket): bool
    {
        $routing = TicketRoutingConditions::matches($ticket, $condition);
        if (null !== $routing) {
            return $routing;
        }
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
            'ticket_type' => $ticket->getTicketType(),
            'channel' => $ticket->getChannel(),
            'tags' => $ticket->getTags()->map(static fn (Tag $tag): string => $tag->getName())->getValues(),
            'hours_since_created' => self::hoursSince($ticket->getCreatedAt()),
            'hours_since_updated' => self::hoursSince($ticket->getUpdatedAt()),
            default => null,
        };
    }

    private static function hoursSince(\DateTimeImmutable $at): float
    {
        return ((new \DateTimeImmutable())->getTimestamp() - $at->getTimestamp()) / 3600;
    }

    private function applyOperator(string $operator, mixed $actual, mixed $expected): bool
    {
        // A list field (tags) matches on its members: `contains` means "has a
        // member equal to", and equality compares against any member.
        if (\is_array($actual)) {
            $members = array_map(static fn ($v): string => mb_strtolower((string) $v), $actual);
            $needle = mb_strtolower((string) ($expected ?? ''));

            return match ($operator) {
                'contains', 'equals' => \in_array($needle, $members, true),
                'not_contains', 'not_equals' => !\in_array($needle, $members, true),
                'is_empty' => [] === $actual,
                'is_not_empty' => [] !== $actual,
                default => false,
            };
        }

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

    /**
     * Runs the actions in order. A `delay` stops the run: the actions after it
     * are stored as delayed actions, due after the delay (and any later delay
     * on top), and ProcessDelayedActionsCommand runs them.
     *
     * @return list<array{type: string, action_type: string, result: string, status: string, error?: string}>
     */
    private function executeActions(array $actions, Ticket $ticket, int $workflowId): array
    {
        $executed = [];
        $actions = array_values($actions);
        foreach ($actions as $index => $action) {
            if (!\is_array($action)) {
                continue;
            }
            if ('delay' === ($action['type'] ?? null)) {
                $this->scheduleDelayed(\array_slice($actions, $index), $ticket, $workflowId);
                $executed[] = $this->detail('delay', 'executed');

                break;
            }

            $error = null;
            $result = $this->executeSingleAction($action, $ticket, $workflowId, $error);
            $executed[] = $this->detail((string) ($action['type'] ?? ''), $result, $error);
        }

        return $executed;
    }

    /** @return array{type: string, action_type: string, result: string, status: string, error?: string} */
    private function detail(string $type, string $result, ?string $error = null): array
    {
        $detail = [
            'type' => $type,
            'action_type' => $type,
            'result' => $result,
            'status' => match ($result) {
                'executed' => 'success',
                'failed' => 'failed',
                default => 'skipped',
            },
        ];
        if (null !== $error) {
            $detail['error'] = $error;
        }

        return $detail;
    }

    /**
     * @param list<mixed> $actions a `delay` followed by the actions it holds back
     */
    private function scheduleDelayed(array $actions, Ticket $ticket, int $workflowId): void
    {
        $minutes = 0;
        foreach ($actions as $action) {
            if (!\is_array($action)) {
                continue;
            }
            if ('delay' === ($action['type'] ?? null)) {
                $minutes += self::delayMinutes($action['value'] ?? null);
                continue;
            }

            $delayed = new DelayedAction();
            $delayed->setWorkflowId($workflowId);
            $delayed->setTicketId((int) $ticket->getId());
            $delayed->setActionData($action);
            $delayed->setExecuteAt(new \DateTimeImmutable(sprintf('+%d minutes', $minutes)));
            $this->em->persist($delayed);
        }
        $this->em->flush();
    }

    /**
     * The builder sends minutes as a scalar; older workflows stored `{"minutes": n}`.
     */
    private static function delayMinutes(mixed $value): int
    {
        $minutes = \is_array($value) ? ($value['minutes'] ?? 0) : $value;

        return is_numeric($minutes) ? max(0, (int) $minutes) : 0;
    }

    /**
     * Runs one action straight on the ticket. Workflow actions do not
     * dispatch ticket events, so a workflow cannot trigger itself.
     *
     * @return string `executed`, `skipped` (unknown type, or nothing to do) or `failed`
     */
    private function executeSingleAction(array $action, Ticket $ticket, int $workflowId, ?string &$error = null): string
    {
        $value = $action['value'] ?? null;
        $text = \is_scalar($value) ? trim((string) $value) : '';
        $body = \is_array($value) ? (string) ($value['body'] ?? '') : $text;

        try {
            $done = match ($action['type'] ?? null) {
                'change_status' => '' !== $text && $this->flushed($ticket->setStatus($text)),
                'assign_agent' => $this->flushed($ticket->setAssignedTo(UserKeyResolver::normalize(\is_string($value) ? trim($value) : $value))),
                'change_priority' => '' !== $text && $this->flushed($ticket->setPriority($text)),
                'add_tag' => $this->addTag($ticket, $text),
                'remove_tag' => $this->removeTag($ticket, $text),
                'set_department', 'move_department' => $this->setDepartment($ticket, $value),
                'add_note', 'add_internal_note' => $this->addNote($ticket, $this->interpolate($body, $ticket)),
                'insert_canned_reply' => $this->addPublicReply($ticket, $this->interpolate($body, $ticket)),
                'set_type' => $this->flushed($ticket->setTicketType('' === $text ? null : $text)),
                'add_follower' => $this->addFollower($ticket, $text),
                default => false,
            };

            return $done ? 'executed' : 'skipped';
        } catch (\Throwable $e) {
            $error = $e->getMessage();

            return 'failed';
        }
    }

    private function flushed(mixed $unused): bool
    {
        $this->em->flush();

        return true;
    }

    /**
     * Adds a tag by name, creating it when no tag has that name or slug.
     */
    private function addTag(Ticket $ticket, string $name): bool
    {
        if ('' === $name) {
            return false;
        }

        $tag = $this->findTag($name);
        if (null === $tag) {
            $tag = (new Tag())->setName($name)->setSlug($this->uniqueTagSlug($name));
            $this->em->persist($tag);
        }
        $ticket->addTag($tag);
        $this->em->flush();

        return true;
    }

    private function removeTag(Ticket $ticket, string $name): bool
    {
        $tag = '' === $name ? null : $this->findTag($name);
        if (null === $tag) {
            return false;
        }
        $ticket->removeTag($tag);
        $this->em->flush();

        return true;
    }

    private function findTag(string $name): ?Tag
    {
        $repository = $this->em->getRepository(Tag::class);

        return $repository->findOneBy(['name' => $name]) ?? $repository->findOneBy(['slug' => self::slug($name)]);
    }

    private function uniqueTagSlug(string $name): string
    {
        $base = self::slug($name);
        $slug = $base;
        for ($i = 2; null !== $this->em->getRepository(Tag::class)->findOneBy(['slug' => $slug]); ++$i) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    private static function slug(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');

        return '' === $slug ? 'tag' : $slug;
    }

    /**
     * The builder sends the department id as a string, e.g. "4".
     */
    private function setDepartment(Ticket $ticket, mixed $value): bool
    {
        $id = \is_array($value) ? ($value['department_id'] ?? null) : $value;
        if (!is_numeric($id)) {
            return false;
        }

        $department = $this->em->getRepository(Department::class)->find((int) $id);
        if (null === $department) {
            throw new \InvalidArgumentException(sprintf('Department %s does not exist.', $id));
        }
        $ticket->setDepartment($department);
        $this->em->flush();

        return true;
    }

    /**
     * A public reply with no author, as add_note writes an internal one. It is
     * not dispatched as reply.created, so a reply workflow cannot answer its
     * own reply.
     */
    private function addPublicReply(Ticket $ticket, string $body): bool
    {
        if ('' === trim($body)) {
            return false;
        }

        $reply = new Reply();
        $reply->setTicket($ticket);
        $reply->setBody($body);
        $reply->setIsInternalNote(false);
        $reply->setType('reply');
        $this->em->persist($reply);
        $this->em->flush();

        return true;
    }

    private function addNote(Ticket $ticket, string $body): bool
    {
        if ('' === trim($body)) {
            return false;
        }

        $reply = new Reply();
        $reply->setTicket($ticket);
        $reply->setBody($body);
        $reply->setIsInternalNote(true);
        $reply->setType('note');
        $this->em->persist($reply);
        $this->em->flush();

        return true;
    }

    private function addFollower(Ticket $ticket, string $userId): bool
    {
        if ('' === $userId || '0' === $userId) {
            return false;
        }

        $repo = $this->em->getRepository(TicketFollower::class);
        if (null !== $repo->findOneBy(['ticketId' => $ticket->getId(), 'userId' => $userId])) {
            return true;
        }

        $follower = new TicketFollower();
        $follower->setTicketId((int) $ticket->getId());
        $follower->setUserId($userId);
        $this->em->persist($follower);
        $this->em->flush();

        return true;
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

    /**
     * Writes a row in the WorkflowLog entity's shape. A run whose conditions did
     * not match executed nothing, so it has no start or completion time.
     */
    private function logExecution(
        int $workflowId,
        int $ticketId,
        string $event,
        bool $conditionsMatched,
        array $actions,
        ?string $error = null,
        ?\DateTimeImmutable $startedAt = null,
    ): void {
        $now = new \DateTimeImmutable();

        $this->em->getConnection()->insert($this->sql('escalated_workflow_logs'), [
            'workflow_id' => $workflowId,
            'ticket_id' => $ticketId,
            'trigger_event' => $event,
            'conditions_matched' => $conditionsMatched,
            'actions_executed' => $actions,
            'error_message' => $error,
            'started_at' => $startedAt,
            'completed_at' => null === $startedAt ? null : $now,
            'created_at' => $now,
        ], [
            'workflow_id' => Types::INTEGER,
            'ticket_id' => Types::INTEGER,
            'trigger_event' => Types::STRING,
            'conditions_matched' => Types::BOOLEAN,
            'actions_executed' => Types::JSON,
            'error_message' => Types::TEXT,
            'started_at' => Types::DATETIME_IMMUTABLE,
            'completed_at' => Types::DATETIME_IMMUTABLE,
            'created_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }

    private function sql(string $sql): string
    {
        return preg_replace('/\bescalated_([a-z_]+)\b/', $this->tablePrefix.'$1', $sql);
    }
}
