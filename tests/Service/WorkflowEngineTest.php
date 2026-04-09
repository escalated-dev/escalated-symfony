<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\WorkflowEngine;
use PHPUnit\Framework\TestCase;

class WorkflowEngineTest extends TestCase
{
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $this->engine = new WorkflowEngine($em);
    }

    public function testEvaluateAndConditions(): void
    {
        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getStatus')->willReturn('open');
        $ticket->method('getPriority')->willReturn('medium');

        $conditions = ['all' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'open'],
            ['field' => 'priority', 'operator' => 'equals', 'value' => 'medium'],
        ]];
        $this->assertTrue($this->engine->evaluateConditions($conditions, $ticket));
    }

    public function testEvaluateOrConditions(): void
    {
        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getStatus')->willReturn('open');

        $conditions = ['any' => [
            ['field' => 'status', 'operator' => 'equals', 'value' => 'closed'],
            ['field' => 'status', 'operator' => 'equals', 'value' => 'open'],
        ]];
        $this->assertTrue($this->engine->evaluateConditions($conditions, $ticket));
    }

    public function testEvaluateNotEquals(): void
    {
        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getStatus')->willReturn('open');

        $conditions = ['all' => [
            ['field' => 'status', 'operator' => 'not_equals', 'value' => 'closed'],
        ]];
        $this->assertTrue($this->engine->evaluateConditions($conditions, $ticket));
    }

    public function testEvaluateContains(): void
    {
        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getSubject')->willReturn('Important billing issue');

        $conditions = ['all' => [
            ['field' => 'subject', 'operator' => 'contains', 'value' => 'billing'],
        ]];
        $this->assertTrue($this->engine->evaluateConditions($conditions, $ticket));
    }

    public function testEvaluateIsEmpty(): void
    {
        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getDescription')->willReturn('');

        $conditions = ['all' => [
            ['field' => 'description', 'operator' => 'is_empty', 'value' => ''],
        ]];
        $this->assertTrue($this->engine->evaluateConditions($conditions, $ticket));
    }

    public function testDryRun(): void
    {
        $ticket = $this->createMock(Ticket::class);
        $ticket->method('getStatus')->willReturn('open');
        $ticket->method('getReference')->willReturn('ESC-001');

        $workflow = [
            'conditions' => json_encode(['all' => [['field' => 'status', 'operator' => 'equals', 'value' => 'open']]]),
            'actions' => json_encode([['type' => 'add_note', 'value' => 'Note for {{reference}}']]),
        ];

        $result = $this->engine->dryRun($workflow, $ticket);
        $this->assertTrue($result['matched']);
        $this->assertStringContainsString('ESC-001', $result['actions'][0]['value']);
    }
}
