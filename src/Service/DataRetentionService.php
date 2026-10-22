<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Escalated\Symfony\Entity\Attachment;
use Escalated\Symfony\Entity\AuditLog;
use Escalated\Symfony\Entity\CustomFieldValue;
use Escalated\Symfony\Entity\DelayedAction;
use Escalated\Symfony\Entity\GuestVerification;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketFollower;

/**
 * The data retention settings (Admin/Settings/DataRetention) and the purge
 * they drive, as escalated-laravel's DataRetentionController and
 * `escalated:purge-expired` command.
 *
 * Every setting defaults to "never", and "never" purges nothing.
 */
class DataRetentionService
{
    public const TICKET_CHOICES = ['never', '1_year', '2_years', '3_years', '5_years'];
    public const ATTACHMENT_CHOICES = self::TICKET_CHOICES;
    public const AUDIT_LOG_CHOICES = ['never', '90_days', '180_days', '365_days'];

    /** Soft-deleted tickets are removed for good after this many days. */
    public const GRACE_DAYS = 30;

    private const DAYS = [
        'never' => null,
        '90_days' => 90,
        '180_days' => 180,
        '365_days' => 365,
        '1_year' => 365,
        '2_years' => 730,
        '3_years' => 1095,
        '5_years' => 1825,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @return array{retention_closed_tickets: string, retention_attachments: string, retention_audit_logs: string, retention_user_data_gdpr: bool}
     */
    public function settings(): array
    {
        return [
            'retention_closed_tickets' => $this->settings->get('retention_closed_tickets', 'never') ?? 'never',
            'retention_attachments' => $this->settings->get('retention_attachments', 'never') ?? 'never',
            'retention_audit_logs' => $this->settings->get('retention_audit_logs', 'never') ?? 'never',
            'retention_user_data_gdpr' => $this->settings->getBool('retention_user_data_gdpr', false),
        ];
    }

    public static function days(?string $choice): ?int
    {
        return self::DAYS[$choice ?? 'never'] ?? null;
    }

    /**
     * What a purge would remove now. Zeros rather than an error when the
     * tables are not there yet.
     *
     * @return array{tickets: int, attachments: int, audit_logs: int}
     */
    public function preview(): array
    {
        $preview = ['tickets' => 0, 'attachments' => 0, 'audit_logs' => 0];

        try {
            if (null !== $cutoff = $this->cutoff('retention_closed_tickets')) {
                $preview['tickets'] = $this->count($this->closedTickets($cutoff));
            }
            if (null !== $cutoff = $this->cutoff('retention_attachments')) {
                $preview['attachments'] = $this->count($this->olderThan(Attachment::class, $cutoff));
            }
            if (null !== $cutoff = $this->cutoff('retention_audit_logs')) {
                $preview['audit_logs'] = $this->count($this->olderThan(AuditLog::class, $cutoff));
            }
        } catch (\Throwable) {
            return ['tickets' => 0, 'attachments' => 0, 'audit_logs' => 0];
        }

        return $preview;
    }

    /**
     * Apply the retention settings. Closed tickets past their cutoff are
     * soft-deleted (or deleted for good with $force); attachments and audit
     * log rows past theirs are deleted; tickets soft-deleted more than
     * {@see GRACE_DAYS} days ago are deleted for good; expired guest
     * verification codes are deleted. With $dryRun nothing changes and the
     * counts say what would have.
     *
     * Each count is null when its setting is "never".
     *
     * @return array{tickets: ?int, attachments: ?int, audit_logs: ?int, grace_expired: int, guest_verifications: int}
     */
    public function purge(bool $dryRun = false, bool $force = false, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $result = ['tickets' => null, 'attachments' => null, 'audit_logs' => null, 'grace_expired' => 0, 'guest_verifications' => 0];

        if (null !== $cutoff = $this->cutoff('retention_closed_tickets', $now)) {
            $ids = $this->ids($this->closedTickets($cutoff));
            $result['tickets'] = \count($ids);
            if (!$dryRun && [] !== $ids) {
                if ($force) {
                    $this->hardDeleteTickets($ids);
                } else {
                    $this->em->createQueryBuilder()
                        ->update(Ticket::class, 't')
                        ->set('t.deletedAt', ':now')
                        ->where('t.id IN (:ids)')
                        ->setParameter('now', $now)
                        ->setParameter('ids', $ids)
                        ->getQuery()
                        ->execute();
                }
            }
        }

        foreach (['attachments' => [Attachment::class, 'retention_attachments'], 'audit_logs' => [AuditLog::class, 'retention_audit_logs']] as $key => [$class, $setting]) {
            if (null === $cutoff = $this->cutoff($setting, $now)) {
                continue;
            }
            $result[$key] = $this->count($this->olderThan($class, $cutoff));
            if (!$dryRun && $result[$key] > 0) {
                $this->em->createQueryBuilder()
                    ->delete($class, 'r')
                    ->where('r.createdAt < :cutoff')
                    ->setParameter('cutoff', $cutoff)
                    ->getQuery()
                    ->execute();
            }
        }

        $graceIds = $this->ids($this->em->createQueryBuilder()
            ->from(Ticket::class, 't')
            ->where('t.deletedAt IS NOT NULL')
            ->andWhere('t.deletedAt < :grace')
            ->setParameter('grace', $now->modify('-'.self::GRACE_DAYS.' days')));
        $result['grace_expired'] = \count($graceIds);
        if (!$dryRun && [] !== $graceIds) {
            $this->hardDeleteTickets($graceIds);
        }

        $expired = $this->em->createQueryBuilder()
            ->from(GuestVerification::class, 'g')
            ->where('g.expiresAt <= :now')
            ->setParameter('now', $now);
        $result['guest_verifications'] = (int) (clone $expired)->select('COUNT(g.id)')->getQuery()->getSingleScalarResult();
        if (!$dryRun && $result['guest_verifications'] > 0) {
            $this->em->createQueryBuilder()
                ->delete(GuestVerification::class, 'g')
                ->where('g.expiresAt <= :now')
                ->setParameter('now', $now)
                ->getQuery()
                ->execute();
        }

        return $result;
    }

    private function cutoff(string $setting, ?\DateTimeImmutable $now = null): ?\DateTimeImmutable
    {
        $days = self::days($this->settings->get($setting, 'never'));

        return null === $days ? null : ($now ?? new \DateTimeImmutable())->modify('-'.$days.' days');
    }

    private function closedTickets(\DateTimeImmutable $cutoff): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->from(Ticket::class, 't')
            ->where('t.status = :closed')
            ->andWhere('t.closedAt < :cutoff')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('closed', Ticket::STATUS_CLOSED)
            ->setParameter('cutoff', $cutoff);
    }

    /**
     * @param class-string $class
     */
    private function olderThan(string $class, \DateTimeImmutable $cutoff): QueryBuilder
    {
        return $this->em->createQueryBuilder()
            ->from($class, 'r')
            ->where('r.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff);
    }

    private function count(QueryBuilder $qb): int
    {
        $alias = $qb->getRootAliases()[0];

        return (int) (clone $qb)->select(sprintf('COUNT(%s.id)', $alias))->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<int>
     */
    private function ids(QueryBuilder $qb): array
    {
        return array_map('intval', (clone $qb)->select('t.id')->getQuery()->getSingleColumnResult());
    }

    /**
     * Delete tickets for good. Replies, attachments, activities and subject
     * links go with them through the ORM; chat sessions, ratings, links, side
     * conversations, tags and workflow logs through their cascading foreign
     * keys; followers, custom field values and delayed actions, which hold the
     * ticket id without a foreign key, are deleted here.
     *
     * @param list<int> $ids
     */
    private function hardDeleteTickets(array $ids): void
    {
        foreach (array_chunk($ids, 100) as $chunk) {
            $this->em->createQueryBuilder()->delete(TicketFollower::class, 'f')->where('f.ticketId IN (:ids)')->setParameter('ids', $chunk)->getQuery()->execute();
            $this->em->createQueryBuilder()->delete(DelayedAction::class, 'd')->where('d.ticketId IN (:ids)')->setParameter('ids', $chunk)->getQuery()->execute();
            $this->em->createQueryBuilder()->delete(CustomFieldValue::class, 'v')->where('v.entityType = :type')->andWhere('v.entityId IN (:ids)')->setParameter('type', 'ticket')->setParameter('ids', $chunk)->getQuery()->execute();

            // Fresh copies, so the cascades see every reply and attachment
            // rather than whatever a managed instance happened to hold.
            $this->em->clear();
            foreach ($this->em->getRepository(Ticket::class)->findBy(['id' => $chunk]) as $ticket) {
                $this->em->remove($ticket);
            }
            $this->em->flush();
            $this->em->clear();
        }
    }
}
