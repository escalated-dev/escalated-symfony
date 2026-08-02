<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\ApiToken;

/**
 * ApiTokenService — generation, hashing and verification of API bearer tokens.
 *
 * Tokens are cryptographically random (32 bytes / 64 hex chars). Only the
 * SHA-256 hash is persisted; the plaintext is returned once at creation and
 * can never be recovered afterwards. Mirrors ApiToken::createToken /
 * findByPlainText in escalated-laravel.
 */
class ApiTokenService
{
    /** Abilities accepted by the token surface, mirroring the Laravel validation. */
    public const ALLOWED_ABILITIES = ['agent', 'admin', '*'];

    /** Minimum seconds between throttled last_used_at writes. */
    private const TOUCH_THROTTLE_SECONDS = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Create and persist a token for the given host user id. Returns both the
     * stored entity and the one-time plaintext token the caller must surface.
     *
     * @param string[] $abilities
     *
     * @return array{token: ApiToken, plainTextToken: string}
     */
    public function createToken(
        int|string $userId,
        string $name,
        array $abilities = ['*'],
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $plainText = bin2hex(random_bytes(32));

        $token = (new ApiToken())
            ->setUserId((string) $userId)
            ->setName($name)
            ->setToken(self::hash($plainText))
            ->setAbilities($this->sanitizeAbilities($abilities))
            ->setExpiresAt($expiresAt);

        $this->em->persist($token);
        $this->em->flush();

        return ['token' => $token, 'plainTextToken' => $plainText];
    }

    /**
     * Resolve a plaintext bearer token to its stored entity by hash, or null
     * when no token matches (unknown or revoked/deleted).
     */
    public function findByPlainText(string $plainText): ?ApiToken
    {
        if ('' === $plainText) {
            return null;
        }

        return $this->em->getRepository(ApiToken::class)
            ->findOneBy(['token' => self::hash($plainText)]);
    }

    /**
     * Record usage, throttled to avoid a write on every request (mirrors the
     * 5-minute throttle in the Laravel middleware).
     */
    public function touchLastUsed(ApiToken $token, ?string $ip): void
    {
        $now = new \DateTimeImmutable();
        $last = $token->getLastUsedAt();

        if (null !== $last && ($now->getTimestamp() - $last->getTimestamp()) < self::TOUCH_THROTTLE_SECONDS) {
            return;
        }

        $token->setLastUsedAt($now)->setLastUsedIp($ip);
        $this->em->flush();
    }

    public static function hash(string $plainText): string
    {
        return hash('sha256', $plainText);
    }

    /**
     * Restrict abilities to the allowed set, de-duplicated. Falls back to the
     * '*' wildcard when nothing valid remains.
     *
     * @param string[] $abilities
     *
     * @return string[]
     */
    private function sanitizeAbilities(array $abilities): array
    {
        $filtered = array_values(array_unique(array_filter(
            $abilities,
            static fn (mixed $a): bool => is_string($a) && in_array($a, self::ALLOWED_ABILITIES, true),
        )));

        return [] === $filtered ? ['*'] : $filtered;
    }
}
