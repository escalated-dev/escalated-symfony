<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;

class AdvancedReportingService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function slaBreachTrends(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $days = $this->dateSeries($from, $to);
        $results = [];
        foreach ($days as $date) {
            $dayStart = (clone $date)->setTime(0, 0);
            $dayEnd = (clone $date)->setTime(23, 59, 59);
            $results[] = [
                'date' => $date->format('Y-m-d'),
                'frt_breaches' => (int) $this->em->createQuery(
                    'SELECT COUNT(t.id) FROM Escalated\Symfony\Entity\Ticket t WHERE t.slaFirstResponseBreached = true AND t.firstResponseAt IS NULL AND t.slaFirstResponseDueAt BETWEEN :start AND :end'
                )->setParameter('start', $dayStart)->setParameter('end', $dayEnd)->getSingleScalarResult(),
                'resolution_breaches' => (int) $this->em->createQuery(
                    'SELECT COUNT(t.id) FROM Escalated\Symfony\Entity\Ticket t WHERE t.slaResolutionBreached = true AND t.resolvedAt IS NULL AND t.slaResolutionDueAt BETWEEN :start AND :end'
                )->setParameter('start', $dayStart)->setParameter('end', $dayEnd)->getSingleScalarResult(),
            ];
        }

        return $results;
    }

    public function frtDistribution(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $tickets = $this->em->createQuery(
            'SELECT t.firstResponseAt, t.createdAt FROM Escalated\Symfony\Entity\Ticket t WHERE t.createdAt BETWEEN :from AND :to AND t.firstResponseAt IS NOT NULL'
        )->setParameter('from', $from)->setParameter('to', $to)->getResult();
        $values = array_map(fn ($t) => round(($t['firstResponseAt']->getTimestamp() - $t['createdAt']->getTimestamp()) / 3600, 2), $tickets);

        return $this->buildDistribution($values, 'hours');
    }

    public function frtTrends(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $days = $this->dateSeries($from, $to);
        $results = [];
        foreach ($days as $date) {
            $dayStart = (clone $date)->setTime(0, 0);
            $dayEnd = (clone $date)->setTime(23, 59, 59);
            $tickets = $this->em->createQuery(
                'SELECT t.firstResponseAt, t.createdAt FROM Escalated\Symfony\Entity\Ticket t WHERE t.firstResponseAt BETWEEN :start AND :end AND t.firstResponseAt IS NOT NULL'
            )->setParameter('start', $dayStart)->setParameter('end', $dayEnd)->getResult();
            $frts = array_map(fn ($t) => ($t['firstResponseAt']->getTimestamp() - $t['createdAt']->getTimestamp()) / 3600, $tickets);
            $results[] = [
                'date' => $date->format('Y-m-d'),
                'avg_hours' => count($frts) > 0 ? round(array_sum($frts) / count($frts), 2) : null,
                'count' => count($frts),
                'percentiles' => count($frts) > 0 ? $this->percentiles($frts) : [],
            ];
        }

        return $results;
    }

    public function frtByAgent(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $tickets = $this->em->createQuery(
            'SELECT t.assignedTo, t.firstResponseAt, t.createdAt FROM Escalated\Symfony\Entity\Ticket t WHERE t.createdAt BETWEEN :from AND :to AND t.firstResponseAt IS NOT NULL AND t.assignedTo IS NOT NULL'
        )->setParameter('from', $from)->setParameter('to', $to)->getResult();
        $grouped = [];
        foreach ($tickets as $t) {
            $grouped[$t['assignedTo']][] = ($t['firstResponseAt']->getTimestamp() - $t['createdAt']->getTimestamp()) / 3600;
        }
        $results = [];
        foreach ($grouped as $agentId => $frts) {
            $results[] = [
                'agent_id' => $agentId,
                'avg_hours' => round(array_sum($frts) / count($frts), 2),
                'count' => count($frts),
                'percentiles' => $this->percentiles($frts),
            ];
        }
        usort($results, fn ($a, $b) => $a['avg_hours'] <=> $b['avg_hours']);

        return $results;
    }

    public function resolutionTimeDistribution(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $tickets = $this->em->createQuery(
            'SELECT t.resolvedAt, t.createdAt FROM Escalated\Symfony\Entity\Ticket t WHERE t.createdAt BETWEEN :from AND :to AND t.resolvedAt IS NOT NULL'
        )->setParameter('from', $from)->setParameter('to', $to)->getResult();
        $values = array_map(fn ($t) => round(($t['resolvedAt']->getTimestamp() - $t['createdAt']->getTimestamp()) / 3600, 2), $tickets);

        return $this->buildDistribution($values, 'hours');
    }

    public function resolutionTimeTrends(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $days = $this->dateSeries($from, $to);
        $results = [];
        foreach ($days as $date) {
            $dayStart = (clone $date)->setTime(0, 0);
            $dayEnd = (clone $date)->setTime(23, 59, 59);
            $tickets = $this->em->createQuery(
                'SELECT t.resolvedAt, t.createdAt FROM Escalated\Symfony\Entity\Ticket t WHERE t.resolvedAt BETWEEN :start AND :end AND t.resolvedAt IS NOT NULL'
            )->setParameter('start', $dayStart)->setParameter('end', $dayEnd)->getResult();
            $times = array_map(fn ($t) => ($t['resolvedAt']->getTimestamp() - $t['createdAt']->getTimestamp()) / 3600, $tickets);
            $results[] = [
                'date' => $date->format('Y-m-d'),
                'avg_hours' => count($times) > 0 ? round(array_sum($times) / count($times), 2) : null,
                'count' => count($times),
                'percentiles' => count($times) > 0 ? $this->percentiles($times) : [],
            ];
        }

        return $results;
    }

    public function agentPerformanceRanking(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $agentIds = $this->em->createQuery(
            'SELECT DISTINCT t.assignedTo FROM Escalated\Symfony\Entity\Ticket t WHERE t.createdAt BETWEEN :from AND :to AND t.assignedTo IS NOT NULL'
        )->setParameter('from', $from)->setParameter('to', $to)->getSingleColumnResult();

        $rankings = [];
        foreach ($agentIds as $agentId) {
            $total = (int) $this->em->createQuery('SELECT COUNT(t.id) FROM Escalated\Symfony\Entity\Ticket t WHERE t.assignedTo = :id AND t.createdAt BETWEEN :from AND :to')
                ->setParameter('id', $agentId)->setParameter('from', $from)->setParameter('to', $to)->getSingleScalarResult();
            $resolved = (int) $this->em->createQuery('SELECT COUNT(t.id) FROM Escalated\Symfony\Entity\Ticket t WHERE t.assignedTo = :id AND t.createdAt BETWEEN :from AND :to AND t.resolvedAt IS NOT NULL')
                ->setParameter('id', $agentId)->setParameter('from', $from)->setParameter('to', $to)->getSingleScalarResult();
            $resRate = $total > 0 ? round($resolved / $total * 100, 1) : 0;
            $composite = $this->compositeScore($resRate, null, null, null);
            $rankings[] = [
                'agent_id' => $agentId,
                'total_tickets' => $total,
                'resolved_count' => $resolved,
                'resolution_rate' => $resRate,
                'composite_score' => $composite,
            ];
        }
        usort($rankings, fn ($a, $b) => ($b['composite_score'] ?? 0) <=> ($a['composite_score'] ?? 0));

        return $rankings;
    }

    public function cohortAnalysis(string $dimension, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return match ($dimension) {
            'department' => $this->cohortByDepartment($from, $to),
            'channel' => $this->cohortByChannel($from, $to),
            'type' => $this->cohortByType($from, $to),
            default => ['error' => "Unknown dimension: {$dimension}"],
        };
    }

    public function periodComparison(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $duration = $to->getTimestamp() - $from->getTimestamp();
        $prevFrom = (clone $from)->modify("-{$duration} seconds");
        $prevTo = clone $from;
        $current = $this->periodStats($from, $to);
        $previous = $this->periodStats($prevFrom, $prevTo);

        return ['current' => $current, 'previous' => $previous, 'changes' => $this->calculateChanges($current, $previous)];
    }

    private function dateSeries(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $days = min(max((int) $from->diff($to)->days + 1, 1), 90);
        $dates = [];
        for ($i = 0; $i < $days; ++$i) {
            $dates[] = (clone $from)->modify("+{$i} days");
        }

        return $dates;
    }

    private function percentiles(array $values): array
    {
        sort($values);

        return [
            'p50' => $this->pct($values, 50),
            'p75' => $this->pct($values, 75),
            'p90' => $this->pct($values, 90),
            'p95' => $this->pct($values, 95),
            'p99' => $this->pct($values, 99),
        ];
    }

    private function pct(array $sorted, int $p): float
    {
        if (1 === count($sorted)) {
            return round($sorted[0], 2);
        }
        $k = ($p / 100) * (count($sorted) - 1);
        $f = (int) floor($k);
        $c = (int) ceil($k);
        if ($f === $c) {
            return round($sorted[$f], 2);
        }

        return round($sorted[$f] + ($k - $f) * ($sorted[$c] - $sorted[$f]), 2);
    }

    private function buildDistribution(array $values, string $unit): array
    {
        if (empty($values)) {
            return ['buckets' => [], 'stats' => []];
        }
        sort($values);
        $max = end($values);
        $bucketSize = max((int) ceil($max / 10), 1);
        $buckets = [];
        for ($start = 0; $start <= (int) ceil($max); $start += $bucketSize) {
            $end = $start + $bucketSize;
            $count = count(array_filter($values, fn ($v) => $v >= $start && $v < $end));
            if ($count > 0) {
                $buckets[] = ['range' => "{$start}-{$end}", 'count' => $count];
            }
        }

        return [
            'buckets' => $buckets,
            'stats' => ['min' => $values[0], 'max' => end($values), 'avg' => round(array_sum($values) / count($values), 2), 'median' => $this->pct($values, 50), 'count' => count($values), 'unit' => $unit],
            'percentiles' => $this->percentiles($values),
        ];
    }

    private function compositeScore(float $resRate, ?float $avgFrt, ?float $avgRes, ?float $avgCsat): float
    {
        $score = ($resRate / 100) * 30;
        $weights = 30.0;
        if (null !== $avgFrt && $avgFrt > 0) {
            $score += max(1 - $avgFrt / 24, 0) * 25;
            $weights += 25;
        }
        if (null !== $avgRes && $avgRes > 0) {
            $score += max(1 - $avgRes / 72, 0) * 25;
            $weights += 25;
        }
        if (null !== $avgCsat) {
            $score += ($avgCsat / 5) * 20;
            $weights += 20;
        }

        return $weights > 0 ? round(($score / $weights) * 100, 1) : 0;
    }

    private function cohortByDepartment(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $depts = $this->em->createQuery('SELECT d FROM Escalated\Symfony\Entity\Department d')->getResult();

        return array_map(fn ($d) => $this->buildCohort($d->getName(), $from, $to, 'department_id', $d->getId()), $depts);
    }

    private function cohortByChannel(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $channels = $this->em->createQuery('SELECT DISTINCT t.channel FROM Escalated\Symfony\Entity\Ticket t WHERE t.createdAt BETWEEN :from AND :to AND t.channel IS NOT NULL')
            ->setParameter('from', $from)->setParameter('to', $to)->getSingleColumnResult();

        return array_map(fn ($ch) => $this->buildCohort($ch, $from, $to, 'channel', $ch), $channels);
    }

    private function cohortByType(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $types = $this->em->createQuery('SELECT DISTINCT t.ticketType FROM Escalated\Symfony\Entity\Ticket t WHERE t.createdAt BETWEEN :from AND :to AND t.ticketType IS NOT NULL')
            ->setParameter('from', $from)->setParameter('to', $to)->getSingleColumnResult();

        return array_map(fn ($type) => $this->buildCohort($type, $from, $to, 'ticket_type', $type), $types);
    }

    private function buildCohort(string $name, \DateTimeInterface $from, \DateTimeInterface $to, string $field, mixed $value): array
    {
        $total = (int) $this->em->createQuery("SELECT COUNT(t.id) FROM Escalated\\Symfony\\Entity\\Ticket t WHERE t.createdAt BETWEEN :from AND :to AND t.{$field} = :val")
            ->setParameter('from', $from)->setParameter('to', $to)->setParameter('val', $value)->getSingleScalarResult();
        $resolved = (int) $this->em->createQuery("SELECT COUNT(t.id) FROM Escalated\\Symfony\\Entity\\Ticket t WHERE t.createdAt BETWEEN :from AND :to AND t.{$field} = :val AND t.resolvedAt IS NOT NULL")
            ->setParameter('from', $from)->setParameter('to', $to)->setParameter('val', $value)->getSingleScalarResult();

        return [
            'name' => $name,
            'total' => $total,
            'resolved' => $resolved,
            'resolution_rate' => $total > 0 ? round($resolved / $total * 100, 1) : 0,
        ];
    }

    private function periodStats(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $total = (int) $this->em->createQuery('SELECT COUNT(t.id) FROM Escalated\Symfony\Entity\Ticket t WHERE t.createdAt BETWEEN :from AND :to')
            ->setParameter('from', $from)->setParameter('to', $to)->getSingleScalarResult();
        $resolved = (int) $this->em->createQuery('SELECT COUNT(t.id) FROM Escalated\Symfony\Entity\Ticket t WHERE t.createdAt BETWEEN :from AND :to AND t.resolvedAt IS NOT NULL')
            ->setParameter('from', $from)->setParameter('to', $to)->getSingleScalarResult();

        return [
            'total_created' => $total,
            'total_resolved' => $resolved,
            'resolution_rate' => $total > 0 ? round($resolved / $total * 100, 1) : 0,
        ];
    }

    private function calculateChanges(array $current, array $previous): array
    {
        $changes = [];
        foreach (['total_created', 'total_resolved', 'resolution_rate'] as $key) {
            $cur = (float) ($current[$key] ?? 0);
            $prev = (float) ($previous[$key] ?? 0);
            $changes[$key] = 0 == $prev ? ($cur > 0 ? 100.0 : 0.0) : round(($cur - $prev) / $prev * 100, 1);
        }

        return $changes;
    }
}
