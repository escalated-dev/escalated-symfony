<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\TwoFactor;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\SecretSealer;
use Escalated\Symfony\Service\TwoFactorService;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;

/**
 * RFC 6238 through pragmarx/google2fa (the old service compared crc32() of
 * the secret, which no authenticator app produces), one step of drift either
 * side, no replay of a step, and secrets sealed at rest.
 */
final class TwoFactorServiceTest extends TestCase
{
    public function testItAcceptsTheRfc6238TestVector(): void
    {
        // RFC 6238 appendix B, SHA-1: T = 59 s gives 94287082 (8 digits),
        // whose last six digits are what a 6-digit authenticator shows.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // base32 of "12345678901234567890"
        $totp = new Google2FA();
        self::assertSame('287082', $totp->oathTotp($secret, 1)); // step 1 = 30..59 s

        self::assertTrue($this->service()->verify($secret, (new Google2FA())->getCurrentOtp($secret)));
    }

    public function testOneStepOfDriftIsAcceptedEitherSideButNotTwo(): void
    {
        $service = $this->service();
        $secret = $service->generateSecret();
        $totp = new Google2FA();
        $now = $totp->getTimestamp();

        self::assertTrue($service->verify($secret, $totp->oathTotp($secret, $now - 1)));
        self::assertTrue($service->verify($secret, $totp->oathTotp($secret, $now + 1)));
        self::assertFalse($service->verify($secret, $totp->oathTotp($secret, $now - 3)));
        self::assertFalse($service->verify($secret, $totp->oathTotp($secret, $now + 3)));
        self::assertFalse($service->verify($secret, 'abcdef'));
    }

    public function testACodeIsConsumedWithItsStep(): void
    {
        $service = $this->service();
        $secret = $service->generateSecret();
        $twoFactor = $this->enrolment($service, $secret);
        $totp = new Google2FA();
        $now = $totp->getTimestamp();

        self::assertTrue($service->verifyAndConsume($twoFactor, $totp->oathTotp($secret, $now)));
        self::assertSame($now, $twoFactor->getLastUsedStep());
        self::assertFalse($service->verifyAndConsume($twoFactor, $totp->oathTotp($secret, $now)), 'the same step again');
        self::assertFalse($service->verifyAndConsume($twoFactor, $totp->oathTotp($secret, $now - 1)), 'an earlier step');
        self::assertTrue($service->verifyAndConsume($twoFactor, $totp->oathTotp($secret, $now + 1)), 'a later step');
    }

    public function testSecretsAndRecoveryCodesAreSealed(): void
    {
        $service = $this->service();
        $twoFactor = $this->enrolment($service, 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');

        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', (string) $twoFactor->getSealedSecret());
        self::assertSame('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', $service->secretOf($twoFactor));
        self::assertCount(8, $service->recoveryCodesOf($twoFactor));
        foreach ($service->recoveryCodesOf($twoFactor) as $code) {
            self::assertMatchesRegularExpression('/^[0-9A-F]{8}-[0-9A-F]{8}$/', $code);
            self::assertStringNotContainsString($code, (string) $twoFactor->getSealedRecoveryCodes());
        }

        // Under another kernel.secret nothing opens.
        $other = new TwoFactorService($this->createMock(EntityManagerInterface::class), new SecretSealer('another-secret'));
        self::assertNull($other->secretOf($twoFactor));
        self::assertSame([], $other->recoveryCodesOf($twoFactor));
    }

    public function testARecoveryCodeWorksOnceIgnoringCase(): void
    {
        $service = $this->service();
        $twoFactor = $this->enrolment($service, $service->generateSecret());
        [$first, $second] = $service->recoveryCodesOf($twoFactor);

        self::assertTrue($service->useRecoveryCode($twoFactor, ' '.strtolower($first).' '));
        self::assertFalse($service->useRecoveryCode($twoFactor, $first));
        self::assertFalse($service->useRecoveryCode($twoFactor, ''));
        self::assertCount(7, $service->recoveryCodesOf($twoFactor));
        self::assertContains($second, $service->recoveryCodesOf($twoFactor));
    }

    public function testRequiredOnlyWhileTheSwitchIsOn(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $sealer = new SecretSealer('s');

        self::assertFalse((new TwoFactorService($em, $sealer, new AccessFeatures(false, false), null, true))->required());
        self::assertTrue((new TwoFactorService($em, $sealer, new AccessFeatures(false, true), null, 'true'))->required());
        self::assertFalse((new TwoFactorService($em, $sealer, new AccessFeatures(false, true), null, false))->required());
    }

    public function testTheQrUriNamesTheIssuerAndAccount(): void
    {
        $service = new TwoFactorService($this->createMock(EntityManagerInterface::class), new SecretSealer('s'), issuer: 'Acme Support');
        $uri = $service->qrUri('JBSWY3DPEHPK3PXP', 'agent@example.com');

        self::assertStringStartsWith('otpauth://totp/Acme%20Support:agent%40example.com?', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=Acme%20Support', $uri);
    }

    private function service(): TwoFactorService
    {
        return new TwoFactorService($this->createMock(EntityManagerInterface::class), new SecretSealer('test-kernel-secret'));
    }

    private function enrolment(TwoFactorService $service, string $secret): TwoFactor
    {
        $twoFactor = new TwoFactor();
        $store = new \ReflectionMethod($service, 'storeSecret');
        $store->invoke($service, $twoFactor, $secret);
        $codes = new \ReflectionMethod($service, 'storeRecoveryCodes');
        $codes->invoke($service, $twoFactor, $service->generateRecoveryCodes());

        return $twoFactor;
    }
}
