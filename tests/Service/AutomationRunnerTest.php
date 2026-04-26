<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Escalated\Symfony\Entity\Automation;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\AutomationRunner;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AutomationRunnerTest extends TestCase
{
    private function makeQbThatReturns(array $tickets): QueryBuilder
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();
        $query->method('getResult')->willReturn($tickets);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        return $qb;
    }

    public function testRunReturnsZeroWhenNoActiveAutomations(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        $runner = new AutomationRunner($em, new NullLogger());
        $this->assertSame(0, $runner->run());
    }

    public function testRunAppliesActionsToMatchingTickets(): void
    {
        $automation = new Automation();
        $automation->setName('close stale')
            ->setConditions([['field' => 'hours_since_created', 'operator' => '>', 'value' => 48]])
            ->setActions([['type' => 'change_status', 'value' => Ticket::STATUS_CLOSED]]);

        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $automationRepo = $this->createMock(EntityRepository::class);
        $automationRepo->method('findBy')->willReturn([$automation]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($automationRepo);
        $em->method('createQueryBuilder')->willReturn($this->makeQbThatReturns([$ticket]));
        $em->expects($this->atLeastOnce())->method('flush');

        $runner = new AutomationRunner($em, new NullLogger());
        $affected = $runner->run();

        $this->assertSame(1, $affected);
        $this->assertSame(Ticket::STATUS_CLOSED, $ticket->getStatus());
        $this->assertNotNull($automation->getLastRunAt());
    }

    public function testOneFailingActionDoesNotAbortSiblings(): void
    {
        $automation = new Automation();
        $automation->setName('multi-action')
            ->setActions([
                ['type' => 'change_status', 'value' => Ticket::STATUS_CLOSED],
                ['type' => 'change_priority', 'value' => 'high'],
            ]);

        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_OPEN);
        $ticket->setPriority('low');

        $automationRepo = $this->createMock(EntityRepository::class);
        $automationRepo->method('findBy')->willReturn([$automation]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($automationRepo);
        $em->method('createQueryBuilder')->willReturn($this->makeQbThatReturns([$ticket]));

        // First flush throws (simulating a DB error on the status change),
        // subsequent flushes succeed. The runner should swallow the failure
        // and continue to the priority action.
        $first = true;
        $em->method('flush')->willReturnCallback(function () use (&$first) {
            if ($first) {
                $first = false;
                throw new \RuntimeException('boom');
            }
        });

        $runner = new AutomationRunner($em, new NullLogger());
        $runner->run();

        $this->assertSame('high', $ticket->getPriority());
    }

    public function testLastRunAtStampedEvenWithNoMatches(): void
    {
        $automation = new Automation();
        $automation->setName('never-matches');

        $automationRepo = $this->createMock(EntityRepository::class);
        $automationRepo->method('findBy')->willReturn([$automation]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($automationRepo);
        $em->method('createQueryBuilder')->willReturn($this->makeQbThatReturns([]));

        $runner = new AutomationRunner($em, new NullLogger());
        $runner->run();

        $this->assertNotNull($automation->getLastRunAt());
    }

    public function testEntityDefaults(): void
    {
        $a = new Automation();
        $this->assertNull($a->getId());
        $this->assertSame('', $a->getName());
        $this->assertTrue($a->isActive());
        $this->assertSame(0, $a->getPosition());
        $this->assertSame([], $a->getConditions());
        $this->assertSame([], $a->getActions());
        $this->assertNull($a->getLastRunAt());
    }
}
