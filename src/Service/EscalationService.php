<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\EscalationRule;
use Escalated\Symfony\Entity\Ticket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * EscalationService — admin time-based escalation rules engine.
 *
 * Evaluates active EscalationRule rows against open tickets and applies
 * their actions (escalate, change priority, (re)assign, change department).
 * Mirrors the Laravel EscalationService. Distinct from AutomationRunner
 * (general automations) and the event-driven WorkflowEngine.
 *
 * A scheduled console command (`escalated:escalations:run`) should invoke
 * `evaluateRules()` every 5 minutes (portfolio convention).
 */
class EscalationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Evaluate all active escalation rules against open tickets and execute
     * matched actions. Returns the (rule × ticket) action count.
     */
    public function evaluateRules(): int
    {
        /** @var EscalationRule[] $rules */
        $rules = $this->em->getRepository(EscalationRule::class)
            ->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'id' => 'ASC']);

        $affected = 0;

        foreach ($rules as $rule) {
            try {
                $tickets = $this->findMatchingTickets($rule);
                foreach ($tickets as $ticket) {
                    $this->executeActions($rule, $ticket);
                    ++$affected;
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        'Escalation rule #%d (%s) failed: %s',
                        $rule->getId(),
                        $rule->getName(),
                        $e->getMessage()
                    )
                );
            }
        }

        return $affected;
    }

    /**
     * @return Ticket[]
     */
    private function findMatchingTickets(EscalationRule $rule): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Ticket::class, 't')
            ->andWhere('t.status NOT IN (:terminal)')
            ->setParameter('terminal', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED]);

        $params = 0;
        foreach ($rule->getConditions() as $condition) {
            $field = $condition['field'] ?? '';
            $value = $condition['value'] ?? null;

            switch ($field) {
                case 'status':
                    $p = 'p'.$params++;
                    $qb->andWhere(sprintf('t.status = :%s', $p))->setParameter($p, $value);
                    break;
                case 'priority':
                    $p = 'p'.$params++;
                    $qb->andWhere(sprintf('t.priority = :%s', $p))->setParameter($p, $value);
                    break;
                case 'assigned':
                    if ('unassigned' === $value) {
                        $qb->andWhere('t.assignedTo IS NULL');
                    } else {
                        $qb->andWhere('t.assignedTo IS NOT NULL');
                    }
                    break;
                case 'age_hours':
                    $p = 'p'.$params++;
                    $qb->andWhere(sprintf('t.createdAt <= :%s', $p))
                       ->setParameter($p, $this->hoursAgo((int) $value));
                    break;
                case 'no_response_hours':
                    $p = 'p'.$params++;
                    $qb->andWhere('t.firstResponseAt IS NULL')
                       ->andWhere(sprintf('t.createdAt <= :%s', $p))
                       ->setParameter($p, $this->hoursAgo((int) $value));
                    break;
                case 'sla_breached':
                    $qb->andWhere('(t.slaFirstResponseBreached = true OR t.slaResolutionBreached = true)');
                    break;
                case 'department_id':
                    $p = 'p'.$params++;
                    $qb->andWhere(sprintf('IDENTITY(t.department) = :%s', $p))
                       ->setParameter($p, (int) $value);
                    break;
                    // Unknown fields skipped silently for forward-compat.
            }
        }

        /** @var Ticket[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    private function executeActions(EscalationRule $rule, Ticket $ticket): void
    {
        foreach ($rule->getActions() as $action) {
            $type = $action['type'] ?? '';
            $value = $action['value'] ?? null;

            try {
                switch ($type) {
                    case 'escalate':
                        $ticket->setStatus(Ticket::STATUS_ESCALATED);
                        $this->em->flush();
                        break;
                    case 'change_priority':
                        $ticket->setPriority((string) $value);
                        $this->em->flush();
                        break;
                    case 'assign_to':
                        $ticket->setAssignedTo((int) $value);
                        $this->em->flush();
                        break;
                    case 'change_department':
                        $department = $this->em->getRepository(Department::class)->find((int) $value);
                        if (null !== $department) {
                            $ticket->setDepartment($department);
                            $this->em->flush();
                        }
                        break;
                        // Unknown action types skipped silently for forward-compat.
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        'Escalation rule #%d action %s on ticket #%d failed: %s',
                        $rule->getId(),
                        $type,
                        $ticket->getId() ?? 0,
                        $e->getMessage()
                    )
                );
            }
        }
    }

    private function hoursAgo(int $hours): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('-%d hours', $hours));
    }
}
