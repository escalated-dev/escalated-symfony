<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\ChatRoutingRule;
use PHPUnit\Framework\TestCase;

class ChatRoutingRuleTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $rule = new ChatRoutingRule();
        $this->assertSame(ChatRoutingRule::STRATEGY_ROUND_ROBIN, $rule->getStrategy());
        $this->assertTrue($rule->isActive());
        $this->assertSame(5, $rule->getMaxConcurrentChats());
        $this->assertSame(0, $rule->getPriority());
        $this->assertNull($rule->getDepartment());
        $this->assertNull($rule->getAgentIds());
    }

    public function testSetters(): void
    {
        $rule = new ChatRoutingRule();
        $rule->setName('Default routing');
        $rule->setStrategy(ChatRoutingRule::STRATEGY_LEAST_ACTIVE);
        $rule->setAgentIds([1, 2, 3]);
        $rule->setPriority(10);
        $rule->setMaxConcurrentChats(3);
        $rule->setIsActive(false);

        $this->assertSame('Default routing', $rule->getName());
        $this->assertSame(ChatRoutingRule::STRATEGY_LEAST_ACTIVE, $rule->getStrategy());
        $this->assertSame([1, 2, 3], $rule->getAgentIds());
        $this->assertSame(10, $rule->getPriority());
        $this->assertSame(3, $rule->getMaxConcurrentChats());
        $this->assertFalse($rule->isActive());
    }

    public function testStrategyConstants(): void
    {
        $this->assertSame('round_robin', ChatRoutingRule::STRATEGY_ROUND_ROBIN);
        $this->assertSame('least_active', ChatRoutingRule::STRATEGY_LEAST_ACTIVE);
        $this->assertSame('department', ChatRoutingRule::STRATEGY_DEPARTMENT);
    }
}
