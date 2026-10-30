<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\TwoFactor;
use PHPUnit\Framework\TestCase;

class TwoFactorTest extends TestCase
{
    public function testGettersAndSetters(): void
    {
        $twoFactor = new TwoFactor();
        $twoFactor->setUserId(42);
        $twoFactor->setMethod('totp');
        $twoFactor->setSealedSecret('sealed-secret');
        $twoFactor->setSealedRecoveryCodes('sealed-codes');
        $twoFactor->setLastUsedStep(59_000_000);

        $this->assertSame(42, $twoFactor->getUserId());
        $this->assertSame('totp', $twoFactor->getMethod());
        $this->assertSame('sealed-secret', $twoFactor->getSealedSecret());
        $this->assertSame('sealed-codes', $twoFactor->getSealedRecoveryCodes());
        $this->assertSame(59_000_000, $twoFactor->getLastUsedStep());
    }

    public function testAnEnrolmentIsPendingUntilConfirmed(): void
    {
        $twoFactor = new TwoFactor();
        $this->assertFalse($twoFactor->isConfirmed());

        $twoFactor->setIsEnabled(true);
        $this->assertTrue($twoFactor->isConfirmed());
    }
}
