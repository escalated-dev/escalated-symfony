<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Entity;

use Escalated\Symfony\Entity\TwoFactor;
use PHPUnit\Framework\TestCase;

class TwoFactorTest extends TestCase
{
    public function testUseRecoveryCodeRemovesCode(): void
    {
        $twoFactor = new TwoFactor();
        $twoFactor->setRecoveryCodes(['code-1', 'code-2', 'code-3']);

        $result = $twoFactor->useRecoveryCode('code-2');

        $this->assertTrue($result);
        $this->assertCount(2, $twoFactor->getRecoveryCodes());
        $this->assertNotContains('code-2', $twoFactor->getRecoveryCodes());
    }

    public function testUseInvalidRecoveryCodeReturnsFalse(): void
    {
        $twoFactor = new TwoFactor();
        $twoFactor->setRecoveryCodes(['code-1']);

        $result = $twoFactor->useRecoveryCode('invalid');

        $this->assertFalse($result);
        $this->assertCount(1, $twoFactor->getRecoveryCodes());
    }

    public function testUseRecoveryCodeWithNullCodesReturnsFalse(): void
    {
        $twoFactor = new TwoFactor();

        $this->assertFalse($twoFactor->useRecoveryCode('code'));
    }

    public function testGettersAndSetters(): void
    {
        $twoFactor = new TwoFactor();
        $twoFactor->setUserId(42);
        $twoFactor->setMethod('totp');
        $twoFactor->setSecret('ABCDEF');
        $twoFactor->setIsEnabled(true);

        $this->assertSame(42, $twoFactor->getUserId());
        $this->assertSame('totp', $twoFactor->getMethod());
        $this->assertSame('ABCDEF', $twoFactor->getSecret());
        $this->assertTrue($twoFactor->isEnabled());
    }
}
