<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\MacroService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MacroServiceTest extends TestCase
{
    public function testEntityDefaults(): void
    {
        $m = new Macro();
        $this->assertNull($m->getId());
        $this->assertSame('', $m->getName());
        $this->assertSame([], $m->getActions());
        $this->assertTrue($m->isShared());
        $this->assertNull($m->getCreatedBy());
    }

    public function testApplyChangesStatus(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('flush');

        $service = new MacroService($em, new NullLogger());

        $macro = new Macro();
        $macro->setActions([['type' => 'change_status', 'value' => Ticket::STATUS_RESOLVED]]);

        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_OPEN);

        $service->apply($macro, $ticket, agentId: 7);

        $this->assertSame(Ticket::STATUS_RESOLVED, $ticket->getStatus());
    }

    public function testApplyAcceptsBothChangeAndSetAliases(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $service = new MacroService($em, new NullLogger());

        $macro = new Macro();
        $macro->setActions([
            ['type' => 'set_priority', 'value' => 'high'],
            ['type' => 'change_priority', 'value' => 'urgent'],
        ]);

        $ticket = new Ticket();
        $ticket->setPriority('low');

        $service->apply($macro, $ticket, agentId: 7);

        // Last action wins.
        $this->assertSame('urgent', $ticket->getPriority());
    }

    public function testApplyAddsReplyForAddReply(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->atLeastOnce())->method('flush');

        $service = new MacroService($em, new NullLogger());

        $macro = new Macro();
        $macro->setActions([['type' => 'add_reply', 'value' => 'Thanks!']]);

        $ticket = new Ticket();

        $service->apply($macro, $ticket, agentId: 7);
    }

    public function testOneFailingActionDoesNotAbortRest(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $first = true;
        $em->method('flush')->willReturnCallback(function () use (&$first) {
            if ($first) {
                $first = false;
                throw new \RuntimeException('first action db error');
            }
        });

        $service = new MacroService($em, new NullLogger());

        $macro = new Macro();
        $macro->setActions([
            ['type' => 'change_status', 'value' => Ticket::STATUS_RESOLVED],
            ['type' => 'change_priority', 'value' => 'high'],
        ]);

        $ticket = new Ticket();
        $ticket->setStatus(Ticket::STATUS_OPEN);
        $ticket->setPriority('low');

        $service->apply($macro, $ticket, agentId: 7);

        // Status was set on the entity even though flush failed; priority
        // also set because the second action ran.
        $this->assertSame('high', $ticket->getPriority());
    }

    public function testCreateBuildsAndPersistsMacro(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $service = new MacroService($em, new NullLogger());

        $macro = $service->create([
            'name' => 'Close + reply',
            'actions' => [['type' => 'change_status', 'value' => 'resolved']],
            'isShared' => true,
            'createdBy' => 42,
        ]);

        $this->assertSame('Close + reply', $macro->getName());
        $this->assertSame(42, $macro->getCreatedBy());
        $this->assertCount(1, $macro->getActions());
    }
}
