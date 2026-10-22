<?php

declare(strict_types=1);

namespace Escalated\Symfony\Rendering;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\SlaPolicy;
use Escalated\Symfony\Entity\Ticket;

/**
 * The report screens' props, as escalated-laravel's Admin\ReportController
 * and ReportingService build them for Escalated/Admin/Reports and
 * Reports/Dashboard, AgentMetrics, SlaReport, CsatReport and Comparison, and
 * the props Reports/AgentDetail declares.
 *
 * Figures cover tickets created in the last `days` days, except the
 * dashboard's status and priority breakdowns, which are all-time as on
 * Laravel. A ticket breached its SLA when either SLA breach flag is set.
 * Ratings are the submitted satisfaction ratings in the window.
 */
class ReportPages
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserDirectory $users,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(int $days): array
    {
        $since = self::since($days);
        $period = $this->tickets($since);
        $rated = $this->ratings($since);

        $breakdown = [];
        foreach ($rated as $rating) {
            $key = (string) $rating['rating'];
            $breakdown[$key] = ($breakdown[$key] ?? 0) + 1;
        }
        ksort($breakdown);

        return [
            'period_days' => $days,
            'total_tickets' => \count($period),
            'resolved_tickets' => $this->resolvedBetween($since, null),
            'avg_first_response_hours' => self::averageHours($period, 'firstResponseAt'),
            'sla_breach_count' => \count(array_filter($period, self::breached(...))),
            'by_status' => (object) self::counts($period, static fn (array $t): ?string => $t['status']),
            'by_priority' => (object) self::counts($period, static fn (array $t): ?string => $t['priority']),
            'csat' => [
                'average' => self::averageRating($rated),
                'total' => \count($rated),
                'breakdown' => (object) $breakdown,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $days): array
    {
        $since = self::since($days);
        $period = $this->tickets($since);
        $all = $this->tickets(null);

        return [
            'period_days' => $days,
            'volume' => self::series(self::counts($period, static fn (array $t): string => $t['createdAt']->format('Y-m-d'))),
            'by_status' => self::series(self::counts($all, static fn (array $t): ?string => $t['status'])),
            'by_priority' => self::series(self::counts($all, static fn (array $t): ?string => $t['priority'])),
            'avg_response_hours' => self::averageHours($period, 'firstResponseAt'),
            'avg_resolution_hours' => self::averageHours($period, 'resolvedAt'),
            'sla_compliance' => self::compliance($period),
            'csat_average' => self::averageRating($this->ratings($since)),
            'agent_performance' => $this->agentPerformance($period),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function agents(int $days): array
    {
        return [
            'period_days' => $days,
            'agents' => $this->agentPerformance($this->tickets(self::since($days))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sla(int $days): array
    {
        $period = $this->tickets(self::since($days));

        $byPolicy = [];
        foreach ($this->em->getRepository(SlaPolicy::class)->findBy(['isActive' => true], ['id' => 'ASC']) as $policy) {
            $covered = array_filter($period, static fn (array $t): bool => (int) $t['policyId'] === $policy->getId());
            $breached = \count(array_filter($covered, self::breached(...)));
            $byPolicy[] = [
                'policy_name' => $policy->getName(),
                'total' => \count($covered),
                'met' => \count($covered) - $breached,
                'breached' => $breached,
            ];
        }

        $breaches = array_values(array_filter($period, self::breached(...)));
        usort($breaches, static fn (array $a, array $b): int => [$b['createdAt'], $b['id']] <=> [$a['createdAt'], $a['id']]);
        $policies = [];
        foreach ($this->em->getRepository(SlaPolicy::class)->findAll() as $policy) {
            $policies[$policy->getId()] = ['id' => $policy->getId(), 'name' => $policy->getName()];
        }
        $assignees = $this->users->findMany(array_column($breaches, 'assignedTo'));

        $rows = [];
        foreach (\array_slice($breaches, 0, 100) as $t) {
            $assignee = null === $t['assignedTo'] ? null : ($assignees[(string) $t['assignedTo']] ?? null);
            $rows[] = [
                'id' => $t['id'],
                'reference' => $t['reference'],
                'subject' => $t['subject'],
                'assigned_to' => $t['assignedTo'],
                'assignee' => null === $assignee ? null : ['id' => $assignee['id'], 'name' => $assignee['name']],
                'sla_policy_id' => null === $t['policyId'] ? null : (int) $t['policyId'],
                'sla_policy' => null === $t['policyId'] ? null : ($policies[(int) $t['policyId']] ?? null),
                'sla_first_response_breached' => (bool) $t['slaFirstResponseBreached'],
                'sla_resolution_breached' => (bool) $t['slaResolutionBreached'],
                'first_response_at' => TicketPresenter::iso($t['firstResponseAt']),
                'resolved_at' => TicketPresenter::iso($t['resolvedAt']),
                'created_at' => TicketPresenter::iso($t['createdAt']),
            ];
        }

        return [
            'period_days' => $days,
            'compliance_rate' => self::compliance($period),
            'compliance_by_policy' => $byPolicy,
            'breaches' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function csat(int $days): array
    {
        $since = self::since($days);
        $rated = $this->ratings($since);
        $ticketCount = \count($this->tickets($since));

        $byAgent = [];
        foreach ($rated as $rating) {
            if (null !== $rating['assignedTo']) {
                $byAgent[(string) $rating['assignedTo']][] = $rating;
            }
        }
        ksort($byAgent);
        $names = $this->users->findMany(array_keys($byAgent));
        $agentRows = [];
        foreach ($byAgent as $agentId => $list) {
            $agentRows[] = [
                'agent_id' => $names[(string) $agentId]['id'] ?? $agentId,
                'agent_name' => $names[(string) $agentId]['name'] ?? (string) $agentId,
                'avg_rating' => self::averageRating($list),
                'total_ratings' => \count($list),
            ];
        }

        $byDate = [];
        foreach ($rated as $rating) {
            $byDate[$rating['createdAt']->format('Y-m-d')][] = $rating;
        }
        ksort($byDate);
        $overTime = [];
        foreach ($byDate as $date => $list) {
            $overTime[] = ['label' => (string) $date, 'value' => self::averageRating($list)];
        }

        return [
            'period_days' => $days,
            'csat_average' => self::averageRating($rated),
            'response_rate' => $ticketCount > 0 ? round(\count($rated) * 100 / $ticketCount, 1) : 0,
            'total_ratings' => \count($rated),
            'by_agent' => $agentRows,
            'over_time' => $overTime,
        ];
    }

    /**
     * The last `days` days beside the `days` before them, each a full set of
     * figures.
     *
     * @return array<string, mixed>
     */
    public function comparison(int $days): array
    {
        $currentStart = self::since($days);
        $previousStart = self::since(2 * $days);

        return [
            'period_days' => $days,
            'current' => $this->period($currentStart, null),
            'previous' => $this->period($previousStart, $currentStart),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function period(\DateTimeImmutable $start, ?\DateTimeImmutable $end): array
    {
        $created = $this->tickets($start, $end);

        return [
            'total_tickets' => \count($created),
            'resolved_tickets' => $this->resolvedBetween($start, $end),
            'avg_frt' => self::averageHours($created, 'firstResponseAt'),
            'avg_resolution' => self::averageHours($created, 'resolvedAt'),
            'sla_compliance' => self::compliance($created),
            'csat' => self::averageRating($this->ratings($start, $end)),
            'breach_count' => \count(array_filter($created, self::breached(...))),
            'volume_trend' => self::series(self::counts($created, static fn (array $t): string => $t['createdAt']->format('Y-m-d'))),
        ];
    }

    /**
     * Reports/AgentDetail: one agent's last `days` days against the `days`
     * before them, under the names the shared page declares (as
     * escalated-rails, -go, -spring, -nestjs and -dotnet send them;
     * escalated-laravel #260 brings Laravel to the same shape).
     *
     * @return array<string, mixed>
     */
    public function agentDetail(int|string $agentId, int $days): array
    {
        $end = new \DateTimeImmutable('now');
        $start = $end->modify(sprintf('-%d days', $days));
        $current = $this->agentWindow($agentId, $start, $end);
        $previous = $this->agentWindow($agentId, $start->modify(sprintf('-%d days', $days)), $start);
        $user = $this->users->find($agentId);

        return [
            'period_days' => $days,
            'agent' => [
                'id' => $user['id'] ?? $agentId,
                'name' => $user['name'] ?? null,
                'email' => $user['email'] ?? null,
            ],
            ...$current,
            'prev_total_tickets' => $previous['total_tickets'],
            'prev_resolved_tickets' => $previous['resolved_tickets'],
            'prev_avg_frt' => $previous['avg_frt'],
            'prev_avg_resolution' => $previous['avg_resolution'],
            'prev_csat_score' => $previous['csat_score'],
        ];
    }

    /**
     * The agent's tickets created in [start, end): KPIs, response-time
     * percentiles, and the productivity, workload and CSAT charts as
     * `{label, value}` rows. `csat_score` is null without ratings, rather than
     * a zero that reads like a rating.
     *
     * @return array<string, mixed>
     */
    private function agentWindow(int|string $agentId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $tickets = array_values(array_filter(
            $this->tickets($start, $end),
            static fn (array $t): bool => null !== $t['assignedTo'] && (string) $t['assignedTo'] === (string) $agentId,
        ));

        $response = [];
        foreach ($tickets as $t) {
            if ($t['firstResponseAt'] instanceof \DateTimeInterface) {
                $response[] = ($t['firstResponseAt']->getTimestamp() - $t['createdAt']->getTimestamp()) / 3600;
            }
        }
        sort($response);

        $ratings = [];
        if ([] !== $tickets) {
            $ratings = $this->em->createQueryBuilder()
                ->select('r.rating')
                ->from(SatisfactionRating::class, 'r')
                ->where('IDENTITY(r.ticket) IN (:ids)')
                ->andWhere('r.rating > 0')
                ->setParameter('ids', array_column($tickets, 'id'))
                ->getQuery()
                ->getArrayResult();
        }

        // Public replies the agent wrote, and the agent's tickets resolved, per day.
        $days = [];
        $replies = $this->em->createQueryBuilder()
            ->select('r.createdAt')
            ->from(Reply::class, 'r')
            ->where('r.authorId = :agent')
            ->andWhere('r.isInternalNote = false')
            ->andWhere('r.deletedAt IS NULL')
            ->andWhere('r.createdAt >= :start')
            ->andWhere('r.createdAt < :end')
            ->setParameter('agent', $agentId)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getArrayResult();
        foreach ($replies as $reply) {
            $day = $reply['createdAt']->format('Y-m-d');
            $days[$day] ??= [0, 0];
            ++$days[$day][0];
        }
        $resolved = $this->em->createQueryBuilder()
            ->select('t.resolvedAt')
            ->from(Ticket::class, 't')
            ->where('t.deletedAt IS NULL')
            ->andWhere('t.assignedTo = :agent')
            ->andWhere('t.resolvedAt >= :start')
            ->andWhere('t.resolvedAt < :end')
            ->setParameter('agent', $agentId)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getArrayResult();
        foreach ($resolved as $row) {
            $day = $row['resolvedAt']->format('Y-m-d');
            $days[$day] ??= [0, 0];
            ++$days[$day][1];
        }
        ksort($days);
        $productivity = [];
        foreach ($days as $day => [$written, $closed]) {
            $productivity[] = ['label' => (string) $day, 'value' => $written + $closed, 'values' => [$written, $closed]];
        }

        $breakdown = [];
        foreach ($ratings as $rating) {
            $breakdown[(int) $rating['rating']] = ($breakdown[(int) $rating['rating']] ?? 0) + 1;
        }
        ksort($breakdown);
        $csatBreakdown = [];
        foreach ($breakdown as $stars => $count) {
            $csatBreakdown[] = ['label' => $stars.' stars', 'value' => $count];
        }

        $percentiles = [];
        foreach ([50, 75, 90, 95, 99] as $p) {
            $percentiles['p'.$p] = round(self::percentile($response, $p), 1);
        }

        return [
            'total_tickets' => \count($tickets),
            'resolved_tickets' => \count(array_filter($tickets, static fn (array $t): bool => null !== $t['resolvedAt'])),
            'avg_frt' => self::averageHours($tickets, 'firstResponseAt'),
            'avg_resolution' => self::averageHours($tickets, 'resolvedAt'),
            'csat_score' => [] === $ratings ? null : self::averageRating($ratings),
            'response_percentiles' => $percentiles,
            'productivity' => $productivity,
            'workload' => self::series(self::counts($tickets, static fn (array $t): string => $t['createdAt']->format('Y-m-d'))),
            'csat_breakdown' => $csatBreakdown,
        ];
    }

    /**
     * Linear interpolation between closest ranks, as Laravel's
     * ReportingService::percentile.
     *
     * @param list<float> $sorted
     */
    private static function percentile(array $sorted, int $p): float
    {
        if ([] === $sorted) {
            return 0.0;
        }
        $index = $p / 100 * (\count($sorted) - 1);
        $lower = (int) floor($index);
        $upper = min($lower + 1, \count($sorted) - 1);

        return $sorted[$lower] + ($index - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }

    /**
     * Per assignee, as ReportingService::getAgentPerformance.
     *
     * @param list<array<string, mixed>> $period
     *
     * @return list<array<string, mixed>>
     */
    private function agentPerformance(array $period): array
    {
        $byAgent = [];
        foreach ($period as $t) {
            if (null !== $t['assignedTo']) {
                $byAgent[(string) $t['assignedTo']][] = $t;
            }
        }
        ksort($byAgent);
        $names = $this->users->findMany(array_keys($byAgent));

        $rows = [];
        foreach ($byAgent as $agentId => $list) {
            $rows[] = [
                'agent_id' => $names[(string) $agentId]['id'] ?? $agentId,
                'agent_name' => $names[(string) $agentId]['name'] ?? (string) $agentId,
                'total_tickets' => \count($list),
                'resolved_tickets' => \count(array_filter($list, static fn (array $t): bool => null !== $t['resolvedAt'])),
                'avg_response_hours' => self::averageHours($list, 'firstResponseAt'),
                'avg_resolution_hours' => self::averageHours($list, 'resolvedAt'),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tickets(?\DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('t.id', 't.reference', 't.subject', 't.status', 't.priority', 't.assignedTo', 'IDENTITY(t.slaPolicy) AS policyId',
                't.slaFirstResponseBreached', 't.slaResolutionBreached', 't.firstResponseAt', 't.resolvedAt', 't.createdAt')
            ->from(Ticket::class, 't')
            ->where('t.deletedAt IS NULL');
        if (null !== $from) {
            $qb->andWhere('t.createdAt >= :from')->setParameter('from', $from);
        }
        if (null !== $to) {
            $qb->andWhere('t.createdAt < :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getArrayResult();
    }

    /**
     * @return list<array{rating: int, createdAt: \DateTimeImmutable, assignedTo: int|string|null}>
     */
    private function ratings(\DateTimeImmutable $from, ?\DateTimeImmutable $to = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('r.rating', 'r.createdAt', 't.assignedTo')
            ->from(SatisfactionRating::class, 'r')
            ->leftJoin('r.ticket', 't')
            ->where('r.createdAt >= :from')
            ->andWhere('r.rating > 0')
            ->setParameter('from', $from);
        if (null !== $to) {
            $qb->andWhere('r.createdAt < :to')->setParameter('to', $to);
        }

        return $qb->getQuery()->getArrayResult();
    }

    private function resolvedBetween(\DateTimeImmutable $start, ?\DateTimeImmutable $end): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Ticket::class, 't')
            ->where('t.deletedAt IS NULL')
            ->andWhere('t.resolvedAt >= :start')
            ->setParameter('start', $start);
        if (null !== $end) {
            $qb->andWhere('t.resolvedAt < :end')->setParameter('end', $end);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private static function breached(array $ticket): bool
    {
        return (bool) $ticket['slaFirstResponseBreached'] || (bool) $ticket['slaResolutionBreached'];
    }

    /**
     * The share of the period's SLA tickets with no breach, as a percentage;
     * 100 with none.
     *
     * @param list<array<string, mixed>> $period
     */
    private static function compliance(array $period): float
    {
        $covered = array_filter($period, static fn (array $t): bool => null !== $t['policyId']);
        if ([] === $covered) {
            return 100.0;
        }
        $breached = \count(array_filter($covered, self::breached(...)));

        return round((\count($covered) - $breached) * 100 / \count($covered), 1);
    }

    /**
     * @param list<array<string, mixed>> $tickets
     */
    private static function averageHours(array $tickets, string $field): float
    {
        $total = 0.0;
        $counted = 0;
        foreach ($tickets as $t) {
            if ($t[$field] instanceof \DateTimeInterface) {
                $total += ($t[$field]->getTimestamp() - $t['createdAt']->getTimestamp()) / 3600;
                ++$counted;
            }
        }

        return 0 === $counted ? 0.0 : round($total / $counted, 1);
    }

    /**
     * @param list<array{rating: int}> $ratings
     */
    private static function averageRating(array $ratings): float
    {
        return [] === $ratings ? 0.0 : round(array_sum(array_column($ratings, 'rating')) / \count($ratings), 1);
    }

    /**
     * @param list<array<string, mixed>>              $tickets
     * @param callable(array<string, mixed>): ?string $key
     *
     * @return array<string, int>
     */
    private static function counts(array $tickets, callable $key): array
    {
        $counts = [];
        foreach ($tickets as $t) {
            $value = $key($t);
            if (null !== $value) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<string, int> $counts
     *
     * @return list<array{label: string, value: int}>
     */
    private static function series(array $counts): array
    {
        $points = [];
        foreach ($counts as $label => $value) {
            $points[] = ['label' => (string) $label, 'value' => $value];
        }

        return $points;
    }

    private static function since(int $days): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('-%d days', $days));
    }
}
