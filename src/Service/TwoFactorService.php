<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\TwoFactor;
use Escalated\Symfony\Security\AccessFeatures;
use Escalated\Symfony\Security\SecretSealer;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238: SHA-1, 6 digits, 30 s steps) through pragmarx/google2fa,
 * single-use recovery codes, and the enrolment records
 * (ADR 2026-10-29-configurable-access-features, "Two-factor").
 *
 * Secrets and recovery codes are sealed at rest with {@see SecretSealer}.
 */
class TwoFactorService
{
    /** Steps of clock drift accepted either side of now. */
    public const WINDOW = 1;

    /** The runtime setting that wins over `escalated.two_factor.required` once saved. */
    public const REQUIRED_SETTING = 'two_factor_required';

    private const SECRET_CONTEXT = 'escalated-two-factor-secret';
    private const RECOVERY_CONTEXT = 'escalated-two-factor-recovery-codes';

    private readonly Google2FA $google2fa;
    private readonly bool $required;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SecretSealer $sealer,
        private readonly AccessFeatures $features = new AccessFeatures(),
        private readonly ?SettingsService $settings = null,
        mixed $required = false,
        private readonly string $issuer = 'Escalated',
    ) {
        $this->google2fa = new Google2FA();
        $this->google2fa->setWindow(self::WINDOW);
        $this->required = \is_bool($required) ? $required : (\is_scalar($required) && (filter_var($required, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false));
    }

    /** A random 32-character base32 secret (160 bits). */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** The otpauth:// URI an authenticator app reads from a QR code. */
    public function qrUri(string $secret, string $account): string
    {
        return $this->google2fa->getQRCodeUrl('' !== $this->issuer ? $this->issuer : 'Escalated', $account, $secret);
    }

    /** Eight random single-use recovery codes. */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; ++$i) {
            $codes[] = strtoupper(bin2hex(random_bytes(4))).'-'.strtoupper(bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    /**
     * Start (or restart) a pending enrolment for the user: any earlier pending
     * one is replaced. A confirmed enrolment is left alone; disable it first.
     *
     * @return array{secret: string, recovery_codes: list<string>}
     */
    public function begin(int|string $userId): array
    {
        foreach ($this->em->getRepository(TwoFactor::class)->findBy(['userId' => $userId, 'isEnabled' => false]) as $pending) {
            $this->em->remove($pending);
        }

        $secret = $this->generateSecret();
        $codes = $this->generateRecoveryCodes();

        $twoFactor = (new TwoFactor())->setUserId($userId)->setMethod('totp');
        $this->storeSecret($twoFactor, $secret);
        $this->storeRecoveryCodes($twoFactor, $codes);
        $this->em->persist($twoFactor);
        $this->em->flush();

        return ['secret' => $secret, 'recovery_codes' => $codes];
    }

    public function confirm(TwoFactor $twoFactor): void
    {
        $twoFactor->setIsEnabled(true)->setVerifiedAt(new \DateTimeImmutable());
        $this->em->flush();
    }

    /** Remove every enrolment the user has (confirmed or pending); the number removed. */
    public function remove(int|string $userId): int
    {
        $rows = $this->em->getRepository(TwoFactor::class)->findBy(['userId' => $userId]);
        foreach ($rows as $row) {
            $this->em->remove($row);
        }
        $this->em->flush();

        return \count($rows);
    }

    public function confirmedFor(int|string|null $userId): ?TwoFactor
    {
        if (null === $userId) {
            return null;
        }

        return $this->em->getRepository(TwoFactor::class)->findOneBy(['userId' => $userId, 'isEnabled' => true]);
    }

    public function pendingFor(int|string|null $userId): ?TwoFactor
    {
        if (null === $userId) {
            return null;
        }

        return $this->em->getRepository(TwoFactor::class)->findOneBy(['userId' => $userId, 'isEnabled' => false], ['id' => 'DESC']);
    }

    /** Whether the code is valid for the secret right now (no replay check). */
    public function verify(string $secret, string $code): bool
    {
        $code = self::normalizeCode($code);

        return 1 === preg_match('/^\d{6}$/', $code) && true === $this->google2fa->verifyKey($secret, $code, self::WINDOW);
    }

    /**
     * Verify a code for an enrolment and record its time step, refusing a
     * step at or before the last one accepted (so a code works once).
     */
    public function verifyAndConsume(TwoFactor $twoFactor, string $code): bool
    {
        $code = self::normalizeCode($code);
        $secret = $this->secretOf($twoFactor);
        if (null === $secret || 1 !== preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // 0, not null: with null the library returns true instead of the step.
        $step = $this->google2fa->verifyKeyNewer($secret, $code, $twoFactor->getLastUsedStep() ?? 0, self::WINDOW);
        if (!\is_int($step)) {
            return false;
        }

        $twoFactor->setLastUsedStep($step);
        $this->em->flush();

        return true;
    }

    /** Use a recovery code: true, and the code is removed, when it matches one. */
    public function useRecoveryCode(TwoFactor $twoFactor, string $code): bool
    {
        $code = strtoupper(trim($code));
        if ('' === $code) {
            return false;
        }

        $codes = $this->recoveryCodesOf($twoFactor);
        foreach ($codes as $index => $candidate) {
            if (hash_equals(strtoupper($candidate), $code)) {
                unset($codes[$index]);
                $this->storeRecoveryCodes($twoFactor, array_values($codes));
                $this->em->flush();

                return true;
            }
        }

        return false;
    }

    public function secretOf(TwoFactor $twoFactor): ?string
    {
        return $this->sealer->open($twoFactor->getSealedSecret(), self::SECRET_CONTEXT);
    }

    /**
     * @return list<string>
     */
    public function recoveryCodesOf(TwoFactor $twoFactor): array
    {
        $json = $this->sealer->open($twoFactor->getSealedRecoveryCodes(), self::RECOVERY_CONTEXT);
        if (null === $json) {
            return [];
        }
        $codes = json_decode($json, true);

        return \is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
    }

    /**
     * Whether staff must enrol: the `two_factor_required` setting once an
     * admin has saved it, else `escalated.two_factor.required`. Only while the
     * two-factor switch is on.
     */
    public function required(): bool
    {
        if (!$this->features->twoFactor()) {
            return false;
        }

        try {
            return $this->settings?->getBool(self::REQUIRED_SETTING, $this->required) ?? $this->required;
        } catch (\Throwable) {
            // The settings table may not exist yet.
            return $this->required;
        }
    }

    private function storeSecret(TwoFactor $twoFactor, string $secret): void
    {
        $twoFactor->setSealedSecret($this->sealer->seal($secret, self::SECRET_CONTEXT));
    }

    /**
     * @param list<string> $codes
     */
    private function storeRecoveryCodes(TwoFactor $twoFactor, array $codes): void
    {
        $twoFactor->setSealedRecoveryCodes($this->sealer->seal(json_encode($codes, \JSON_THROW_ON_ERROR), self::RECOVERY_CONTEXT));
    }

    private static function normalizeCode(string $code): string
    {
        return preg_replace('/\s+/', '', $code) ?? '';
    }
}
