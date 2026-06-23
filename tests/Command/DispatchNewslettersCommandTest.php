<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Command\DispatchNewslettersCommand;
use Escalated\Symfony\Service\Newsletter\NewsletterDispatcher;
use Escalated\Symfony\Service\Newsletter\NewsletterPlanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DispatchNewslettersCommandTest extends TestCase
{
    public function testDisabledFeatureSkipsWithoutDatabaseWork(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('createQueryBuilder');
        $planner = $this->createMock(NewsletterPlanner::class);
        $dispatcher = $this->createMock(NewsletterDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatchBatch');

        $tester = new CommandTester(new DispatchNewslettersCommand($em, $planner, $dispatcher, false));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Newsletter feature disabled', $tester->getDisplay());
    }
}
