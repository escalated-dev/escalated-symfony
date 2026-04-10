<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\TwoFactor;

class TwoFactorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function enable(int $userId, string $method = 'totp'): TwoFactor
    {
        $twoFactor = new TwoFactor();
        $twoFactor->setUserId($userId);
        $twoFactor->setMethod($method);
        $twoFactor->setSecret($this->generateSecret());
        $twoFactor->setRecoveryCodes($this->generateRecoveryCodes());
        $twoFactor->setIsEnabled(true);

        $this->em->persist($twoFactor);
        $this->em->flush();

        return $twoFactor;
    }

    public function verify(TwoFactor $twoFactor, string $code): bool
    {
        if (!$twoFactor->isEnabled()) {
            return false;
        }

        $valid = $this->verifyTotp($twoFactor->getSecret(), $code);

        if ($valid && null === $twoFactor->getVerifiedAt()) {
            $twoFactor->setVerifiedAt(new \DateTimeImmutable());
            $this->em->flush();
        }

        return $valid;
    }

    public function verifyRecoveryCode(TwoFactor $twoFactor, string $code): bool
    {
        if ($twoFactor->useRecoveryCode($code)) {
            $this->em->flush();

            return true;
        }

        return false;
    }

    public function disable(TwoFactor $twoFactor): void
    {
        $twoFactor->setIsEnabled(false);
        $twoFactor->setSecret(null);
        $twoFactor->setRecoveryCodes(null);
        $this->em->flush();
    }

    public function findByUser(int $userId): ?TwoFactor
    {
        return $this->em->getRepository(TwoFactor::class)->findOneBy(['userId' => $userId, 'isEnabled' => true]);
    }

    public function regenerateRecoveryCodes(TwoFactor $twoFactor): array
    {
        $codes = $this->generateRecoveryCodes();
        $twoFactor->setRecoveryCodes($codes);
        $this->em->flush();

        return $codes;
    }

    private function generateSecret(int $length = 32): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; ++$i) {
            $secret .= $chars[random_int(0, 31)];
        }

        return $secret;
    }

    private function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; ++$i) {
            $codes[] = sprintf('%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    private function verifyTotp(?string $secret, string $code): bool
    {
        if (!$secret) {
            return false;
        }
        // Simplified TOTP verification: in production, use a proper TOTP library
        $timeSlice = (int) floor(time() / 30);
        for ($i = -1; $i <= 1; ++$i) {
            $expected = str_pad((string) (crc32($secret.($timeSlice + $i)) % 1000000), 6, '0', STR_PAD_LEFT);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }
}
