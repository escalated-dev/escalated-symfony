<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\EscalationRule;
use PHPUnit\Framework\TestCase;

final class EscalationRuleTest extends TestCase
{
    public function testDefaults(): void
    {
        $rule = new EscalationRule();

        self::assertSame('', $rule->getName());
        self::assertTrue($rule->isActive());
        self::assertSame(0, $rule->getSortOrder());
        self::assertSame([], $rule->getConditions());
        self::assertSame([], $rule->getActions());
    }

    public function testFluentSettersAndGetters(): void
    {
        $rule = (new EscalationRule())
            ->setName('escalate-high')
            ->setDescription('High priority escalation')
            ->setTriggerType('cron')
            ->setConditions([['field' => 'priority', 'value' => 'high']])
            ->setActions([['type' => 'escalate']])
            ->setSortOrder(3)
            ->setIsActive(false);

        self::assertSame('escalate-high', $rule->getName());
        self::assertSame('High priority escalation', $rule->getDescription());
        self::assertSame('cron', $rule->getTriggerType());
        self::assertSame([['field' => 'priority', 'value' => 'high']], $rule->getConditions());
        self::assertSame([['type' => 'escalate']], $rule->getActions());
        self::assertSame(3, $rule->getSortOrder());
        self::assertFalse($rule->isActive());
    }
}
