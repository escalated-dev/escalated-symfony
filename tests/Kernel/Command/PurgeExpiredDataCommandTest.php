<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Command;

use Escalated\Symfony\Entity\Attachment;
use Escalated\Symfony\Entity\AuditLog;
use Escalated\Symfony\Entity\GuestVerification;
use Escalated\Symfony\Entity\Reply;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Entity\TicketFollower;
use Escalated\Symfony\Service\SettingsService;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * `escalated:purge-expired`, escalated-laravel's PurgeExpiredDataCommand:
 * applies the retention settings saved on Admin/Settings/DataRetention.
 */
final class PurgeExpiredDataCommandTest extends EscalatedKernelTestCase
{
    private ApplicationTester $console;

    protected function setUp(): void
    {
        self::bootKernel();
        self::createSchema();

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $this->console = new ApplicationTester($application);
    }

    public function testNeverLeavesEverythingAlone(): void
    {
        $old = $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-10 years'));
        $this->attachment($old, '-10 years');
        $this->auditLog('-10 years');

        $this->purge([]);

        self::assertSame(1, $this->rows(Ticket::class, 'deletedAt IS NULL'));
        self::assertSame(1, $this->rows(Attachment::class));
        self::assertSame(1, $this->rows(AuditLog::class));
        self::assertStringContainsString('Closed tickets: retention set to never', $this->console->getDisplay());
    }

    public function testOldClosedTicketsAreSoftDeletedAndRecentOrOpenOnesKept(): void
    {
        $this->set('retention_closed_tickets', '1_year');
        $old = $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-400 days'));
        $recent = $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-300 days'));
        $open = $this->ticket(Ticket::STATUS_OPEN, null);
        $resolved = $this->ticket(Ticket::STATUS_RESOLVED, new \DateTimeImmutable('-400 days'));

        $this->purge([]);

        $em = self::entityManager();
        $em->clear();
        self::assertNotNull($em->find(Ticket::class, $old->getId())?->getDeletedAt());
        foreach ([$recent, $open, $resolved] as $kept) {
            self::assertNull($em->find(Ticket::class, $kept->getId())?->getDeletedAt());
        }
        self::assertStringContainsString('Closed tickets: 1', $this->console->getDisplay());
    }

    public function testForceDeletesClosedTicketsForGoodWithTheirRows(): void
    {
        $this->set('retention_closed_tickets', '2_years');
        $old = $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-800 days'));
        $kept = $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-700 days'));
        $em = self::entityManager();
        $reply = (new Reply())->setTicket($old)->setBody('Thanks');
        $em->persist($reply);
        $em->persist((new TicketFollower())->setTicketId((int) $old->getId())->setUserId(7));
        $em->flush();
        $oldId = $old->getId();

        $this->purge(['--force' => true]);

        $em->clear();
        self::assertNull($em->find(Ticket::class, $oldId));
        self::assertNotNull($em->find(Ticket::class, $kept->getId()));
        self::assertSame(0, $this->rows(Reply::class));
        self::assertSame(0, $this->rows(TicketFollower::class));
    }

    public function testDryRunDeletesNothing(): void
    {
        $this->set('retention_closed_tickets', '1_year');
        $this->set('retention_attachments', '1_year');
        $this->set('retention_audit_logs', '90_days');
        $old = $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-400 days'));
        $this->attachment($old, '-400 days');
        $this->auditLog('-100 days');
        $gone = $this->ticket(Ticket::STATUS_CLOSED, new \DateTimeImmutable('-10 days'));
        $gone->setDeletedAt(new \DateTimeImmutable('-31 days'));
        self::entityManager()->flush();

        $this->purge(['--dry-run' => true]);

        self::assertSame(2, $this->rows(Ticket::class));
        self::assertSame(1, $this->rows(Ticket::class, 'deletedAt IS NULL'));
        self::assertSame(1, $this->rows(Attachment::class));
        self::assertSame(1, $this->rows(AuditLog::class));
        $display = $this->console->getDisplay();
        self::assertStringContainsString('DRY RUN', $display);
        self::assertStringContainsString('Closed tickets: 1', $display);
        self::assertStringContainsString('Attachments: 1', $display);
        self::assertStringContainsString('Audit logs: 1', $display);
        self::assertStringContainsString('grace period: 1', $display);
    }

    public function testAttachmentsAndAuditLogsPastTheirCutoffAreDeleted(): void
    {
        $this->set('retention_attachments', '1_year');
        $this->set('retention_audit_logs', '90_days');
        $ticket = $this->ticket(Ticket::STATUS_OPEN, null);
        $this->attachment($ticket, '-400 days');
        $this->attachment($ticket, '-100 days');
        $this->auditLog('-100 days');
        $this->auditLog('-10 days');

        $this->purge([]);

        self::assertSame(1, $this->rows(Attachment::class));
        self::assertSame(1, $this->rows(AuditLog::class));
        self::assertSame(1, $this->rows(Ticket::class));
    }

    public function testSoftDeletedTicketsPastTheGracePeriodAndExpiredGuestCodesAreRemoved(): void
    {
        $expired = $this->ticket(Ticket::STATUS_OPEN, null);
        $expired->setDeletedAt(new \DateTimeImmutable('-31 days'));
        $grace = $this->ticket(Ticket::STATUS_OPEN, null);
        $grace->setDeletedAt(new \DateTimeImmutable('-5 days'));
        $em = self::entityManager();
        $em->persist(new GuestVerification('00000000-0000-0000-0000-000000000001', str_repeat('a', 64), 'lookup', str_repeat('b', 64), new \DateTimeImmutable('-1 minute')));
        $em->persist(new GuestVerification('00000000-0000-0000-0000-000000000002', str_repeat('a', 64), 'lookup', str_repeat('c', 64), new \DateTimeImmutable('+10 minutes')));
        $em->flush();
        $expiredId = $expired->getId();

        $this->purge([]);

        $em->clear();
        self::assertNull($em->find(Ticket::class, $expiredId));
        self::assertNotNull($em->find(Ticket::class, $grace->getId()));
        self::assertSame(1, $this->rows(GuestVerification::class));
        self::assertNotNull($em->find(GuestVerification::class, '00000000-0000-0000-0000-000000000002'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function purge(array $options): void
    {
        $exitCode = $this->console->run(['command' => 'escalated:purge-expired'] + $options, ['interactive' => false]);
        self::assertSame(0, $exitCode, $this->console->getDisplay());
    }

    private function set(string $key, string $value): void
    {
        $settings = self::getContainer()->get(SettingsService::class);
        \assert($settings instanceof SettingsService);
        $settings->set($key, $value);
    }

    private function ticket(string $status, ?\DateTimeImmutable $closedAt): Ticket
    {
        $ticket = (new Ticket())
            ->setSubject('Retention')
            ->setReference('ESC-P'.bin2hex(random_bytes(4)))
            ->setStatus($status)
            ->setClosedAt($closedAt);
        $em = self::entityManager();
        $em->persist($ticket);
        $em->flush();

        return $ticket;
    }

    private function attachment(Ticket $ticket, string $age): void
    {
        $em = self::entityManager();
        $attachment = (new Attachment())->setTicket($ticket)->setOriginalFilename('a.txt')->setStoredFilename('a.txt')->setPath('a.txt');
        $em->persist($attachment);
        $em->flush();
        $em->createQuery('UPDATE '.Attachment::class.' a SET a.createdAt = :d WHERE a.id = :id')->setParameter('d', new \DateTimeImmutable($age))->setParameter('id', $attachment->getId())->execute();
    }

    private function auditLog(string $age): void
    {
        $em = self::entityManager();
        $log = (new AuditLog())->setAction('updated')->setEntityType('ticket');
        $em->persist($log);
        $em->flush();
        $em->createQuery('UPDATE '.AuditLog::class.' a SET a.createdAt = :d WHERE a.id = :id')->setParameter('d', new \DateTimeImmutable($age))->setParameter('id', $log->getId())->execute();
    }

    /**
     * @param class-string $class
     */
    private function rows(string $class, ?string $where = null): int
    {
        $qb = self::entityManager()->createQueryBuilder()->select('COUNT(r)')->from($class, 'r');
        if (null !== $where) {
            $qb->where('r.'.$where);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
