<?php

declare(strict_types=1);

namespace Escalated\Symfony\Rendering;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Department;
use Escalated\Symfony\Entity\SatisfactionRating;
use Escalated\Symfony\Entity\Ticket;

/**
 * The analytics report screens' props, as escalated-laravel's
 * Admin\ReportController builds them (through ReportingService and
 * Support\ReportScreens) for Reports/SlaTrends, ResponseTimes,
 * ResolutionTimes, AgentRanking and Cohorts.
 *
 * Every figure covers tickets created in the last `days` days. Charts read
 * `{label, value}`; the agent and cohort tables read their own column names.
 * Rows a database would return in no set order are sorted by label.
 */
class ReportScreens
{
    /** What the response-time screen measures "on target" against (the component's default). */
    public const FIRST_RESPONSE_TARGET_HOURS = 4;

    /** The same for the resolution-time screen. */
    public const RESOLUTION_TARGET_HOURS = 24;

    private const FIRST_RESPONSE_BUCKETS = ['<1h' => [0, 1], '1-4h' => [1, 4], '4-8h' => [4, 8], '8-24h' => [8, 24], '>24h' => [24, null]];

    private const RESOLUTION_BUCKETS = ['<4h' => [0, 4], '4-8h' => [4, 8], '8-24h' => [8, 24], '1-3d' => [24, 72], '3-7d' => [72, 168], '>7d' => [168, null]];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserDirectory $users,
        private readonly ReportPages $pages,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function slaTrends(int $days, string $groupBy = 'day'): array
    {
        $period = $this->tickets($days);
        $breached = array_values(array_filter($period, self::breached(...)));

        $byPeriod = [];
        foreach ($breached as $t) {
            $key = self::periodKey($t['createdAt'], $groupBy);
            $byPeriod[$key] ??= [0, 0, 0];
            $byPeriod[$key][0] += $t['slaFirstResponseBreached'] ? 1 : 0;
            $byPeriod[$key][1] += $t['slaResolutionBreached'] ? 1 : 0;
            ++$byPeriod[$key][2];
        }
        ksort($byPeriod);

        $trend = [];
        $byType = [];
        foreach ($byPeriod as $label => [$firstResponse, $resolution, $total]) {
            $trend[] = ['label' => (string) $label, 'value' => $total];
            $byType[] = ['label' => (string) $label, 'values' => [$firstResponse, $resolution]];
        }

        $covered = array_values(array_filter($period, static fn (array $t): bool => null !== $t['policyId']));
        $departments = $this->departmentNames($covered);

        return [
            'period_days' => $days,
            'breach_trend' => $trend,
            'breach_by_type_trend' => $byType,
            'breach_by_department' => self::breachRates($covered, static fn (array $t): string => $departments[(int) $t['departmentId']] ?? 'Unassigned'),
            'breach_by_priority' => self::breachRates($covered, static fn (array $t): string => self::label($t['priority'])),
            'at_risk_tickets' => $this->atRiskTickets(),
            'total_breaches' => \count($breached),
            'breach_rate' => [] === $period ? 0.0 : round(\count($breached) * 100 / \count($period), 1),
            'first_response_breaches' => \count(array_filter($period, static fn (array $t): bool => (bool) $t['slaFirstResponseBreached'])),
            'resolution_breaches' => \count(array_filter($period, static fn (array $t): bool => (bool) $t['slaResolutionBreached'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function responseTimes(int $days): array
    {
        $timed = self::timed($this->tickets($days), 'firstResponseAt');
        $summary = self::summary(array_column($timed, 'hours'), self::FIRST_RESPONSE_TARGET_HOURS);
        $departments = $this->departmentNames($timed);

        return [
            'period_days' => $days,
            'avg_frt' => $summary['avg'],
            'median_frt' => $summary['median'],
            'p90_frt' => $summary['p90'],
            'pct_under_target' => $summary['pct_under_target'],
            'target_hours' => self::FIRST_RESPONSE_TARGET_HOURS,
            'distribution' => self::histogram(array_column($timed, 'hours'), self::FIRST_RESPONSE_BUCKETS),
            'trend' => self::trend($timed),
            'by_agent' => $this->agentTimes($timed),
            'by_department' => self::averages($timed, static fn (array $t): string => $departments[(int) $t['departmentId']] ?? 'Unassigned'),
            'by_priority' => self::averages($timed, static fn (array $t): string => self::label($t['priority'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolutionTimes(int $days): array
    {
        $timed = self::timed($this->tickets($days), 'resolvedAt');
        $summary = self::summary(array_column($timed, 'hours'), self::RESOLUTION_TARGET_HOURS);
        $departments = $this->departmentNames($timed);

        return [
            'period_days' => $days,
            'avg_resolution' => $summary['avg'],
            'median_resolution' => $summary['median'],
            'p90_resolution' => $summary['p90'],
            'pct_under_target' => $summary['pct_under_target'],
            'target_hours' => self::RESOLUTION_TARGET_HOURS,
            'distribution' => self::histogram(array_column($timed, 'hours'), self::RESOLUTION_BUCKETS),
            'trend' => self::trend($timed),
            'by_agent' => $this->agentTimes($timed),
            'by_department' => self::averages($timed, static fn (array $t): string => $departments[(int) $t['departmentId']] ?? 'Unassigned'),
            'by_channel' => self::averages($timed, static fn (array $t): string => self::label($t['channel'])),
        ];
    }

    /**
     * Agents by composite score, as ReportingService::agentPerformanceRanking:
     * resolution rate 30%, first response 25%, CSAT 25%, volume 20%.
     *
     * @return array<string, mixed>
     */
    public function agentRanking(int $days): array
    {
        $since = self::since($days);
        $csat = [];
        foreach ($this->ratings($since) as $rating) {
            if (null !== $rating['assignedTo']) {
                $csat[(string) $rating['assignedTo']][] = (int) $rating['rating'];
            }
        }

        $ranked = [];
        foreach ($this->pages->agents($days)['agents'] as $agent) {
            $total = (int) $agent['total_tickets'];
            $resolutionRate = $total > 0 ? $agent['resolved_tickets'] / $total * 100 : 0.0;
            $ratings = $csat[(string) $agent['agent_id']] ?? [];
            $csatAverage = [] === $ratings ? 0.0 : array_sum($ratings) / \count($ratings);
            $responseHours = (float) $agent['avg_response_hours'];

            $frtScore = $responseHours > 0 ? max(0, 100 - $responseHours * 5) : 50;
            $composite = round($resolutionRate * 0.30 + $frtScore * 0.25 + $csatAverage * 20 * 0.25 + min(100, $total * 2) * 0.20, 1);

            $ranked[] = [
                'agent_id' => $agent['agent_id'],
                'agent_name' => $agent['agent_name'],
                'volume' => $total,
                'resolution_rate' => round($resolutionRate, 1),
                'avg_frt' => $responseHours,
                'avg_resolution' => (float) $agent['avg_resolution_hours'],
                'csat' => round($csatAverage, 1),
                'composite_score' => $composite,
            ];
        }
        usort($ranked, static fn (array $a, array $b): int => $b['composite_score'] <=> $a['composite_score']);

        return ['period_days' => $days, 'agents' => $ranked];
    }

    /**
     * Volume, average resolution, breach rate and CSAT per tag, department,
     * channel, type and priority.
     *
     * @return array<string, mixed>
     */
    public function cohorts(int $days): array
    {
        $period = $this->tickets($days);
        $departments = $this->departmentNames($period);

        $ratings = [];
        foreach ($this->ratings(self::since($days)) as $rating) {
            if (null !== $rating['ticketId']) {
                $ratings[(int) $rating['ticketId']][] = (int) $rating['rating'];
            }
        }

        $byTag = [];
        foreach ($this->tagged(array_column($period, 'id')) as [$ticketId, $tag]) {
            $byTag[$tag][] = $ticketId;
        }
        $index = array_column($period, null, 'id');
        $tagRows = [];
        ksort($byTag);
        foreach ($byTag as $tag => $ids) {
            $tagRows[] = self::cohort((string) $tag, array_map(static fn (int $id): array => $index[$id], $ids), $ratings);
        }

        return [
            'period_days' => $days,
            'by_tag' => $tagRows,
            'by_department' => self::cohortsBy($period, static fn (array $t): string => $departments[(int) $t['departmentId']] ?? 'Unassigned', $ratings),
            'by_channel' => self::cohortsBy($period, static fn (array $t): string => self::label($t['channel']), $ratings),
            'by_type' => self::cohortsBy($period, static fn (array $t): string => null === $t['ticketType'] || '' === $t['ticketType'] ? 'unspecified' : (string) $t['ticketType'], $ratings),
            'by_priority' => self::cohortsBy($period, static fn (array $t): string => null === $t['priority'] || '' === $t['priority'] ? 'unspecified' : (string) $t['priority'], $ratings),
        ];
    }

    /**
     * Open tickets whose next unmet SLA deadline falls within the next eight
     * hours, soonest first (Support\ReportScreens::atRiskTickets).
     *
     * @return list<array{id: int, reference: string, subject: string, hours_remaining: float}>
     */
    private function atRiskTickets(int $withinHours = 8, int $limit = 50): array
    {
        $now = new \DateTimeImmutable();
        $deadline = $now->modify(sprintf('+%d hours', $withinHours));

        $rows = $this->em->createQueryBuilder()
            ->select('t.id', 't.reference', 't.subject', 't.firstResponseAt', 't.firstResponseDueAt', 't.resolvedAt', 't.resolutionDueAt',
                't.slaFirstResponseBreached', 't.slaResolutionBreached')
            ->from(Ticket::class, 't')
            ->where('t.deletedAt IS NULL')
            ->andWhere('t.status NOT IN (:closed)')
            ->andWhere('(t.firstResponseAt IS NULL AND t.slaFirstResponseBreached = false AND t.firstResponseDueAt BETWEEN :now AND :deadline)'
                .' OR (t.resolvedAt IS NULL AND t.slaResolutionBreached = false AND t.resolutionDueAt BETWEEN :now AND :deadline)')
            ->setParameter('closed', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
            ->setParameter('now', $now)
            ->setParameter('deadline', $deadline)
            ->getQuery()
            ->getArrayResult();

        $tickets = [];
        foreach ($rows as $t) {
            $due = array_filter([
                null === $t['firstResponseAt'] ? $t['firstResponseDueAt'] : null,
                null === $t['resolvedAt'] ? $t['resolutionDueAt'] : null,
            ], static fn (?\DateTimeImmutable $at): bool => null !== $at && $at >= $now && $at <= $deadline);
            if ([] === $due) {
                continue;
            }
            $tickets[] = [
                'id' => $t['id'],
                'reference' => $t['reference'],
                'subject' => $t['subject'],
                'hours_remaining' => round((min($due)->getTimestamp() - $now->getTimestamp()) / 3600, 1),
            ];
        }
        usort($tickets, static fn (array $a, array $b): int => $a['hours_remaining'] <=> $b['hours_remaining']);

        return \array_slice($tickets, 0, $limit);
    }

    /**
     * The agent table on the time screens; unassigned tickets are not an agent.
     *
     * @param list<array<string, mixed>> $timed
     *
     * @return list<array<string, mixed>>
     */
    private function agentTimes(array $timed): array
    {
        $byAgent = [];
        foreach ($timed as $t) {
            if (null !== $t['assignedTo'] && '' !== $t['assignedTo']) {
                $byAgent[(string) $t['assignedTo']][] = $t['hours'];
            }
        }
        ksort($byAgent);
        $names = $this->users->findMany(array_keys($byAgent));

        $rows = [];
        foreach ($byAgent as $agentId => $hours) {
            sort($hours);
            $rows[] = [
                'agent_id' => $names[(string) $agentId]['id'] ?? $agentId,
                'agent_name' => $names[(string) $agentId]['name'] ?? (string) $agentId,
                'count' => \count($hours),
                'avg' => round(array_sum($hours) / \count($hours), 1),
                'median' => round(self::percentile($hours, 50), 1),
                'p90' => round(self::percentile($hours, 90), 1),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $tickets
     *
     * @return array<int, string>
     */
    private function departmentNames(array $tickets): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $t): int => (int) $t['departmentId'], $tickets))));
        if ([] === $ids) {
            return [];
        }

        $names = [];
        foreach ($this->em->getRepository(Department::class)->findBy(['id' => $ids]) as $department) {
            $names[(int) $department->getId()] = $department->getName();
        }

        return $names;
    }

    /**
     * @param list<int> $ticketIds
     *
     * @return list<array{0: int, 1: string}>
     */
    private function tagged(array $ticketIds): array
    {
        if ([] === $ticketIds) {
            return [];
        }

        $rows = $this->em->createQueryBuilder()
            ->select('t.id', 'g.name')
            ->from(Ticket::class, 't')
            ->join('t.tags', 'g')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ticketIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [(int) $row['id'], (string) $row['name']], $rows);
    }

    /**
     * The period's tickets with the hours from creation to `$field`, for
     * those that have it.
     *
     * @param list<array<string, mixed>> $tickets
     *
     * @return list<array<string, mixed>>
     */
    private static function timed(array $tickets, string $field): array
    {
        $timed = [];
        foreach ($tickets as $t) {
            if ($t[$field] instanceof \DateTimeInterface) {
                $t['hours'] = ($t[$field]->getTimestamp() - $t['createdAt']->getTimestamp()) / 3600;
                $timed[] = $t;
            }
        }

        return $timed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tickets(int $days): array
    {
        return $this->em->createQueryBuilder()
            ->select('t.id', 't.priority', 't.channel', 't.ticketType', 't.assignedTo', 'IDENTITY(t.slaPolicy) AS policyId', 'IDENTITY(t.department) AS departmentId',
                't.slaFirstResponseBreached', 't.slaResolutionBreached', 't.firstResponseAt', 't.resolvedAt', 't.createdAt')
            ->from(Ticket::class, 't')
            ->where('t.deletedAt IS NULL')
            ->andWhere('t.createdAt >= :from')
            ->setParameter('from', self::since($days))
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return list<array{rating: int, ticketId: int|null, assignedTo: int|string|null}>
     */
    private function ratings(\DateTimeImmutable $from): array
    {
        return $this->em->createQueryBuilder()
            ->select('r.rating', 't.id AS ticketId', 't.assignedTo')
            ->from(SatisfactionRating::class, 'r')
            ->leftJoin('r.ticket', 't')
            ->where('r.createdAt >= :from')
            ->andWhere('r.rating > 0')
            ->setParameter('from', $from)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @param list<array<string, mixed>>             $tickets
     * @param callable(array<string, mixed>): string $label
     *
     * @return list<array{label: string, value: float|int}>
     */
    private static function breachRates(array $tickets, callable $label): array
    {
        $groups = self::group($tickets, $label);
        $rows = [];
        foreach ($groups as $name => $list) {
            $breached = \count(array_filter($list, self::breached(...)));
            $rows[] = ['label' => (string) $name, 'value' => round($breached * 100 / \count($list), 1)];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>>             $timed
     * @param callable(array<string, mixed>): string $label
     *
     * @return list<array{label: string, value: float}>
     */
    private static function averages(array $timed, callable $label): array
    {
        $rows = [];
        foreach (self::group($timed, $label) as $name => $list) {
            $hours = array_column($list, 'hours');
            $rows[] = ['label' => (string) $name, 'value' => round(array_sum($hours) / \count($hours), 1)];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>>             $tickets
     * @param callable(array<string, mixed>): string $label
     * @param array<int, list<int>>                  $ratings
     *
     * @return list<array<string, mixed>>
     */
    private static function cohortsBy(array $tickets, callable $label, array $ratings): array
    {
        $rows = [];
        foreach (self::group($tickets, $label) as $name => $list) {
            $rows[] = self::cohort((string) $name, $list, $ratings);
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $tickets
     * @param array<int, list<int>>      $ratings
     *
     * @return array{name: string, volume: int, avg_resolution: float, breach_rate: float|int, csat: float}
     */
    private static function cohort(string $name, array $tickets, array $ratings): array
    {
        $resolved = array_column(self::timed($tickets, 'resolvedAt'), 'hours');
        $scores = [];
        foreach ($tickets as $t) {
            array_push($scores, ...($ratings[(int) $t['id']] ?? []));
        }
        $volume = \count($tickets);

        return [
            'name' => $name,
            'volume' => $volume,
            'avg_resolution' => [] === $resolved ? 0.0 : round(array_sum($resolved) / \count($resolved), 1),
            'breach_rate' => $volume > 0 ? round(\count(array_filter($tickets, self::breached(...))) * 100 / $volume, 1) : 0,
            'csat' => [] === $scores ? 0.0 : round(array_sum($scores) / \count($scores), 1),
        ];
    }

    /**
     * @param list<array<string, mixed>>             $tickets
     * @param callable(array<string, mixed>): string $label
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function group(array $tickets, callable $label): array
    {
        $groups = [];
        foreach ($tickets as $t) {
            $groups[$label($t)][] = $t;
        }
        ksort($groups, \SORT_STRING);

        return $groups;
    }

    /**
     * @param list<array<string, mixed>> $timed
     *
     * @return list<array{label: string, value: float}>
     */
    private static function trend(array $timed): array
    {
        return self::averages($timed, static fn (array $t): string => $t['createdAt']->format('Y-m-d'));
    }

    /**
     * @param list<float>                         $hours
     * @param array<string, array{int, int|null}> $buckets
     *
     * @return list<array{label: string, value: int}>
     */
    private static function histogram(array $hours, array $buckets): array
    {
        $rows = [];
        foreach ($buckets as $label => [$min, $max]) {
            $rows[] = [
                'label' => $label,
                'value' => \count(array_filter($hours, static fn (float $h): bool => $h >= $min && (null === $max || $h < $max))),
            ];
        }

        return $rows;
    }

    /**
     * @param list<float> $hours
     *
     * @return array{avg: float, median: float, p90: float, pct_under_target: float}
     */
    private static function summary(array $hours, float $targetHours): array
    {
        if ([] === $hours) {
            return ['avg' => 0.0, 'median' => 0.0, 'p90' => 0.0, 'pct_under_target' => 0.0];
        }
        sort($hours);
        $within = \count(array_filter($hours, static fn (float $h): bool => $h <= $targetHours));

        return [
            'avg' => round(array_sum($hours) / \count($hours), 1),
            'median' => round(self::percentile($hours, 50), 1),
            'p90' => round(self::percentile($hours, 90), 1),
            'pct_under_target' => round($within * 100 / \count($hours), 1),
        ];
    }

    /**
     * @param list<float> $sorted
     */
    private static function percentile(array $sorted, float $percentile): float
    {
        $count = \count($sorted);
        if (0 === $count) {
            return 0.0;
        }
        $index = $percentile / 100 * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $lower === $upper ? (float) $sorted[$lower] : $sorted[$lower] + ($index - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }

    private static function periodKey(\DateTimeInterface $at, string $groupBy): string
    {
        return match ($groupBy) {
            'week' => $at->format('o-\WW'),
            'month' => $at->format('Y-m'),
            default => $at->format('Y-m-d'),
        };
    }

    /**
     * @param array<string, mixed> $ticket
     */
    private static function breached(array $ticket): bool
    {
        return (bool) $ticket['slaFirstResponseBreached'] || (bool) $ticket['slaResolutionBreached'];
    }

    private static function label(mixed $value): string
    {
        return null === $value || '' === $value ? 'unknown' : (string) $value;
    }

    private static function since(int $days): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('-%d days', $days));
    }
}
