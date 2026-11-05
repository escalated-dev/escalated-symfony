<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketFollower;
use Escalated\Symfony\Security\UserKeyResolver;

/**
 * The agent ticket list's filters, applied to a ticket query.
 *
 * The list and the agent inbox counts both go through here, so a count and
 * the list it labels cannot disagree about what a filter means.
 */
final class TicketListFilters
{
    /** How close a due SLA target has to be for the ticket to be "at risk". */
    public const SLA_AT_RISK_MINUTES = 60;

    public const MAX_UPDATED_WITHIN_HOURS = 8760;

    /** The query keys the repository's filtered query builder reads. */
    private const REPOSITORY_FILTERS = ['status', 'priority', 'ticket_type', 'assigned_to', 'department_id', 'search'];

    private const SORTS = ['created_at' => 'createdAt', 'updated_at' => 'updatedAt', 'subject' => 'subject', 'reference' => 'reference', 'status' => 'status', 'priority' => 'priority'];

    private const BOOLEANS = ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Field errors for the filters added for the agent panel.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, string>
     */
    public static function errors(array $filters): array
    {
        $errors = [];
        foreach (['open', 'sla_at_risk', 'resolved_today'] as $key) {
            $value = $filters[$key] ?? null;
            if (null === $value || '' === $value) {
                continue;
            }
            if (!\is_scalar($value) || !\in_array(strtolower((string) $value), self::BOOLEANS, true)) {
                $errors[$key] = sprintf('The %s field must be true or false.', str_replace('_', ' ', $key));
            }
        }
        $hours = $filters['updated_within_hours'] ?? null;
        if (null !== $hours && '' !== $hours) {
            if (!\is_scalar($hours) || !preg_match('/^\d+$/', (string) $hours) || (int) $hours < 1 || (int) $hours > self::MAX_UPDATED_WITHIN_HOURS) {
                $errors['updated_within_hours'] = 'The updated within hours field must be between 1 and 8760.';
            }
        }

        return $errors;
    }

    /** `1`, `true`, `on` or `yes`, as escalated-laravel's boolean filters read. */
    public static function flag(array $filters, string $key): bool
    {
        $value = $filters[$key] ?? null;

        return true === $value || 1 === $value
            || (\is_string($value) && \in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true));
    }

    /**
     * The list's query for these filters, ordered by `sort_by` / `sort_dir`.
     *
     * @param array<string, mixed> $filters
     */
    public function query(array $filters, int|string|null $me): QueryBuilder
    {
        $base = array_intersect_key($filters, array_flip(self::REPOSITORY_FILTERS));
        $base = array_filter($base, static fn (mixed $v): bool => \is_scalar($v) && '' !== (string) $v);
        if (isset($filters['sort_by']) && \is_string($filters['sort_by'])) {
            $base['sort_by'] = self::SORTS[$filters['sort_by']] ?? $filters['sort_by'];
        }
        if (isset($filters['sort_dir']) && \is_string($filters['sort_dir'])) {
            $base['sort_dir'] = $filters['sort_dir'];
        }
        if (isset($base['assigned_to'])) {
            $base['assigned_to'] = UserKeyResolver::normalize($base['assigned_to']);
        }

        $qb = $this->em->getRepository(Ticket::class)->createFilteredQueryBuilder($base);
        $qb->addOrderBy('t.id', 'DESC');

        $unassigned = $filters['unassigned'] ?? null;
        if (null !== $unassigned && '' !== $unassigned && '0' !== (string) $unassigned && 'false' !== (string) $unassigned) {
            $qb->andWhere('t.assignedTo IS NULL');
        }
        if (!empty($filters['following']) && null !== $me) {
            $qb->andWhere('t.id IN (SELECT tf.ticketId FROM '.TicketFollower::class.' tf WHERE tf.userId = :followerId)')
                ->setParameter('followerId', $me);
        }
        if (self::flag($filters, 'open')) {
            $qb->andWhere('t.status NOT IN (:openExcluded)')
                ->setParameter('openExcluded', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED]);
        }
        if (self::flag($filters, 'sla_at_risk')) {
            $qb->andWhere('t.status NOT IN (:atRiskExcluded)')
                ->andWhere('t.slaFirstResponseBreached = true OR t.slaResolutionBreached = true'
                    .' OR (t.firstResponseAt IS NULL AND t.firstResponseDueAt <= :atRiskSoon)'
                    .' OR t.resolutionDueAt <= :atRiskSoon')
                ->setParameter('atRiskExcluded', [Ticket::STATUS_RESOLVED, Ticket::STATUS_CLOSED])
                ->setParameter('atRiskSoon', new \DateTimeImmutable(sprintf('+%d minutes', self::SLA_AT_RISK_MINUTES)));
        }
        if (self::flag($filters, 'resolved_today')) {
            $qb->andWhere('t.resolvedAt >= :resolvedSince')
                ->setParameter('resolvedSince', new \DateTimeImmutable('today'));
        }
        $hours = $filters['updated_within_hours'] ?? null;
        if (\is_scalar($hours) && preg_match('/^\d+$/', (string) $hours) && (int) $hours > 0) {
            $qb->andWhere('t.updatedAt >= :updatedSince')
                ->setParameter('updatedSince', new \DateTimeImmutable(sprintf('-%d hours', (int) $hours)));
        }

        // Snoozed tickets are left out unless asked for, as escalated-laravel's list does.
        if (self::flag($filters, 'snoozed')) {
            $qb->andWhere('t.snoozedUntil > :snoozeNow')->setParameter('snoozeNow', new \DateTimeImmutable());
        } elseif (!self::flag($filters, 'include_snoozed')) {
            $qb->andWhere('t.snoozedUntil IS NULL OR t.snoozedUntil <= :snoozeNow')
                ->setParameter('snoozeNow', new \DateTimeImmutable());
        }

        return $qb;
    }

    /**
     * The number of tickets the list shows for these filters.
     *
     * @param array<string, mixed> $filters
     */
    /**
     * Of the tickets these filters list, those unread for the agent (ADR
     * 2026-11-05-agent-panel-data).
     */
    public function countUnread(array $filters, int|string $me): int
    {
        $qb = $this->query($filters, $me);
        $qb->select('COUNT(DISTINCT t.id)')->resetDQLPart('orderBy');
        AgentPanel::onlyUnreadQuery($qb, $me);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function count(array $filters, int|string|null $me): int
    {
        $qb = $this->query($filters, $me);
        $qb->select('COUNT(DISTINCT t.id)')->resetDQLPart('orderBy');

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
