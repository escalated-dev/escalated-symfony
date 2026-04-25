<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Automation;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Tag;
use Escalated\Symfony\Entity\Ticket;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * AutomationRunner — admin time-based rules engine.
 *
 * Distinct from WorkflowEngine (event-driven) and Macro (agent manual).
 * See escalated-developer-context/domain-model/workflows-automations-macros.md.
 *
 * A scheduled console command (e.g. `bin/console escalated:automations:run`)
 * or a cron entry should invoke `run()` periodically (every 5 min is the
 * convention used across the portfolio).
 */
class AutomationRunner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Evaluate all active automations against open tickets and execute
     * matched actions. Returns the (automation × ticket) action count.
     */
    public function run(): int
    {
        /** @var Automation[] $automations */
        $automations = $this->em->getRepository(Automation::class)
            ->findBy(['active' => true], ['position' => 'ASC', 'id' => 'ASC']);

        $affected = 0;

        foreach ($automations as $automation) {
            try {
                $tickets = $this->findMatchingTickets($automation);
                foreach ($tickets as $ticket) {
                    $this->executeActions($automation, $ticket);
                    ++$affected;
                }
                $automation->setLastRunAt(new \DateTimeImmutable());
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        'Automation #%d (%s) failed: %s',
                        $automation->getId(),
                        $automation->getName(),
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
    private function findMatchingTickets(Automation $automation): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('t')
            ->from(Ticket::class, 't')
            ->andWhere('t.status NOT IN (:terminal)')
            ->setParameter('terminal', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED]);

        $params = 0;
        foreach ($automation->getConditions() as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? '>';
            $value = $condition['value'] ?? null;

            switch ($field) {
                case 'hours_since_created':
                    $threshold = $this->hoursAgo((int) $value);
                    $p = 'p'.$params++;
                    $qb->andWhere(sprintf('t.createdAt %s :%s', $this->flipOperator($operator), $p))
                       ->setParameter($p, $threshold);
                    break;

                case 'hours_since_updated':
                    $threshold = $this->hoursAgo((int) $value);
                    $p = 'p'.$params++;
                    $qb->andWhere(sprintf('t.updatedAt %s :%s', $this->flipOperator($operator), $p))
                       ->setParameter($p, $threshold);
                    break;

                case 'hours_since_assigned':
                    $threshold = $this->hoursAgo((int) $value);
                    $p = 'p'.$params++;
                    $qb->andWhere('t.assignedTo IS NOT NULL')
                       ->andWhere(sprintf('t.updatedAt %s :%s', $this->flipOperator($operator), $p))
                       ->setParameter($p, $threshold);
                    break;

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
                    } elseif ('assigned' === $value) {
                        $qb->andWhere('t.assignedTo IS NOT NULL');
                    }
                    break;
                case 'subject_contains':
                    $p = 'p'.$params++;
                    $qb->andWhere(sprintf('t.subject LIKE :%s', $p))
                       ->setParameter($p, '%'.$value.'%');
                    break;
                    // Unknown fields skipped silently for forward-compat.
            }
        }

        /** @var Ticket[] $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    private function executeActions(Automation $automation, Ticket $ticket): void
    {
        foreach ($automation->getActions() as $action) {
            $type = $action['type'] ?? '';
            $value = $action['value'] ?? null;

            try {
                switch ($type) {
                    case 'change_status':
                        $ticket->setStatus((string) $value);
                        $this->em->flush();
                        break;
                    case 'change_priority':
                        $ticket->setPriority((string) $value);
                        $this->em->flush();
                        break;
                    case 'assign':
                        $ticket->setAssignedTo((int) $value);
                        $this->em->flush();
                        break;
                    case 'add_tag':
                        $tag = $this->em->getRepository(Tag::class)
                            ->findOneBy(['name' => (string) $value]);
                        if (null !== $tag && !$ticket->getTags()->contains($tag)) {
                            $ticket->getTags()->add($tag);
                            $this->em->flush();
                        }
                        break;

                    case 'add_note':
                        $reply = new Reply();
                        $reply->setTicket($ticket);
                        $reply->setBody((string) $value);
                        $reply->setIsInternalNote(true);
                        $reply->setMetadata([
                            'system_note' => true,
                            'automation_id' => $automation->getId(),
                        ]);
                        $this->em->persist($reply);
                        $this->em->flush();
                        break;
                        // Unknown action types skipped silently for forward-compat.
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    sprintf(
                        'Automation #%d action %s on ticket #%d failed: %s',
                        $automation->getId(),
                        $type,
                        $ticket->getId() ?? 0,
                        $e->getMessage()
                    )
                );
            }
        }
    }

    /**
     * "hours_since > N" means the ticket's timestamp is older than N hours
     * ago, i.e. timestamp < (now - N hours). The SQL operator is the
     * inverse of the user-facing one.
     */
    private function flipOperator(string $op): string
    {
        return match ($op) {
            '>' => '<',
            '>=' => '<=',
            '<' => '>',
            '<=' => '>=',
            '=' => '=',
            default => '<',
        };
    }

    private function hoursAgo(int $hours): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->modify(sprintf('-%d hours', $hours));
    }
}
