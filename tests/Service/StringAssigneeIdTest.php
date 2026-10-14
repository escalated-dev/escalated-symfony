<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Automation;
use Escalated\Symfony\Entity\EscalationRule;
use Escalated\Symfony\Entity\Macro;
use Escalated\Symfony\Entity\Ticket;
use Escalated\Symfony\Service\AutomationRunner;
use Escalated\Symfony\Service\EscalationService;
use Escalated\Symfony\Service\MacroService;
use Escalated\Symfony\Service\WorkflowEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Rule, macro, automation and workflow assign actions cast the agent id to
 * int, so a UUID/ULID host had every assignee written as 0 (or the id's
 * leading digits). The id must keep the shape the host's key type uses.
 */
class StringAssigneeIdTest extends TestCase
{
    private const ULID = '01JABCDEFGHJKMNPQRSTVWXYZ0';

    private string|false $previousKeyType;

    protected function setUp(): void
    {
        $this->previousKeyType = getenv('ESCALATED_USER_KEY_TYPE');
    }

    protected function tearDown(): void
    {
        unset($_ENV['ESCALATED_USER_KEY_TYPE']);
        false === $this->previousKeyType
            ? putenv('ESCALATED_USER_KEY_TYPE')
            : putenv('ESCALATED_USER_KEY_TYPE='.$this->previousKeyType);
    }

    public static function assignments(): iterable
    {
        yield 'uuid host keeps a ULID' => ['uuid', self::ULID, self::ULID];
        yield 'string host keeps a numeric-looking id as a string' => ['string', '42', '42'];
        yield 'int host still stores an int' => ['int', '7', 7];
    }

    #[DataProvider('assignments')]
    public function testEscalationRuleAssignKeepsTheKey(string $keyType, string $value, int|string $expected): void
    {
        $this->useKeyType($keyType);
        $rule = (new EscalationRule())->setActions([['type' => 'assign_to', 'value' => $value]]);
        $ticket = new Ticket();

        $this->invokePrivate(new EscalationService($this->em()), 'executeActions', $rule, $ticket);

        $this->assertSame($expected, $ticket->getAssignedTo());
    }

    #[DataProvider('assignments')]
    public function testMacroAssignKeepsTheKey(string $keyType, string $value, int|string $expected): void
    {
        $this->useKeyType($keyType);
        $macro = new Macro();
        $macro->setActions([['type' => 'assign', 'value' => $value]]);
        $ticket = new Ticket();

        (new MacroService($this->em(), new NullLogger()))->apply($macro, $ticket, agentId: 1);

        $this->assertSame($expected, $ticket->getAssignedTo());
    }

    #[DataProvider('assignments')]
    public function testAutomationAssignKeepsTheKey(string $keyType, string $value, int|string $expected): void
    {
        $this->useKeyType($keyType);
        $automation = (new Automation())->setActions([['type' => 'assign', 'value' => $value]]);
        $ticket = new Ticket();

        $this->invokePrivate(new AutomationRunner($this->em(), new NullLogger()), 'executeActions', $automation, $ticket);

        $this->assertSame($expected, $ticket->getAssignedTo());
    }

    #[DataProvider('assignments')]
    public function testWorkflowAssignKeepsTheKey(string $keyType, string $value, int|string $expected): void
    {
        $this->useKeyType($keyType);
        $ticket = new Ticket();

        $this->invokePrivate(new WorkflowEngine($this->em()), 'executeSingleAction', ['type' => 'assign_agent', 'value' => $value], $ticket, 1);

        $this->assertSame($expected, $ticket->getAssignedTo());
    }

    private function useKeyType(string $keyType): void
    {
        $_ENV['ESCALATED_USER_KEY_TYPE'] = $keyType;
        putenv('ESCALATED_USER_KEY_TYPE='.$keyType);
    }

    private function em(): EntityManagerInterface
    {
        return $this->createMock(EntityManagerInterface::class);
    }

    private function invokePrivate(object $service, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($service, $method))->invoke($service, ...$args);
    }
}
