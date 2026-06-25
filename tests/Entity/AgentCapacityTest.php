<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\AgentCapacity;
use PHPUnit\Framework\TestCase;

final class AgentCapacityTest extends TestCase
{
    public function testDefaults(): void
    {
        $cap = new AgentCapacity();

        self::assertSame('default', $cap->getChannel());
        self::assertSame(10, $cap->getMaxConcurrent());
        self::assertSame(0, $cap->getCurrentCount());
        self::assertTrue($cap->hasCapacity());
    }

    public function testHasCapacityReflectsHeadroom(): void
    {
        $cap = (new AgentCapacity())->setMaxConcurrent(3)->setCurrentCount(2);
        self::assertTrue($cap->hasCapacity());

        $cap->setCurrentCount(3);
        self::assertFalse($cap->hasCapacity());

        $cap->setCurrentCount(4);
        self::assertFalse($cap->hasCapacity());
    }

    public function testCurrentCountNeverNegative(): void
    {
        $cap = (new AgentCapacity())->setCurrentCount(-5);

        self::assertSame(0, $cap->getCurrentCount());
    }

    public function testLoadPercentage(): void
    {
        $cap = (new AgentCapacity())->setMaxConcurrent(10)->setCurrentCount(3);
        self::assertSame(30.0, $cap->loadPercentage());

        $cap->setMaxConcurrent(8)->setCurrentCount(2);
        self::assertSame(25.0, $cap->loadPercentage());
    }

    public function testLoadPercentageWithZeroCeilingIsFullyLoaded(): void
    {
        $cap = (new AgentCapacity())->setMaxConcurrent(0)->setCurrentCount(0);

        self::assertSame(100.0, $cap->loadPercentage());
    }
}
