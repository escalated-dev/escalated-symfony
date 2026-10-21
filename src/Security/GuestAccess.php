<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\Ticket;

/**
 * Expiring guest grants (escalated-laravel docs/guest-access.md).
 *
 * A grant is an opaque, encrypted and authenticated token (AES-256-GCM with a
 * key derived from `kernel.secret`) naming one ticket, its purpose, a digest
 * of the verified email, a random nonce and an expiry. The ticket stores only
 * the nonce's SHA-256 and the expiry, so issuing a new grant, changing the
 * guest email or {@see revoke()} invalidates every earlier grant.
 *
 * Issue a grant only after {@see GuestEmailVerification} has consumed a proof
 * for the ticket's email.
 *
 * Tickets created before grants carry a permanent guest token. While
 * `guest_access.accept_legacy_tokens` is on (the default for now), that token
 * keeps working on the widget's ticket routes until a grant is issued for the
 * ticket, which retires it there. A live chat keeps addressing its session by
 * the guest token.
 */
class GuestAccess
{
    public const PURPOSE_TICKET = 'ticket';

    private const VERSION = 1;
    private const CIPHER = 'aes-256-gcm';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $secret,
        private readonly int $ttlMinutes = 1440,
        private readonly bool $acceptLegacyTokens = true,
    ) {
    }

    /**
     * Marks the ticket's email as verified and returns a new grant, which
     * replaces any earlier one.
     *
     * @return array{token: string, expires_at: \DateTimeImmutable}
     */
    public function issue(Ticket $ticket, string $verifiedEmail, string $purpose = self::PURPOSE_TICKET): array
    {
        if (self::PURPOSE_TICKET !== $purpose) {
            throw new \InvalidArgumentException(sprintf('Unsupported guest access purpose "%s".', $purpose));
        }
        $email = GuestEmailVerification::normalize($verifiedEmail);
        if ('' === $email || GuestEmailVerification::normalize($ticket->getGuestEmail()) !== $email) {
            throw new \InvalidArgumentException('Guest access needs the ticket email that was verified.');
        }
        if (null === $ticket->getId()) {
            throw new \InvalidArgumentException('Guest access needs a saved ticket.');
        }

        $nonce = bin2hex(random_bytes(32));
        $now = new \DateTimeImmutable('@'.time());
        $expiresAt = $now->modify(sprintf('+%d minutes', $this->ttlMinutes()));
        $ticket->setGuestAccess($email, $now, hash('sha256', $nonce), $expiresAt);
        $this->em->flush();

        return [
            'token' => $this->seal([
                'v' => self::VERSION,
                'ticket' => (string) $ticket->getId(),
                'purpose' => $purpose,
                'email' => hash('sha256', $email),
                'nonce' => $nonce,
                'expires' => $expiresAt->getTimestamp(),
            ]),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * The ticket a grant names, or null when it is forged, expired, rotated,
     * revoked or for another purpose.
     */
    public function resolve(?string $token, string $purpose = self::PURPOSE_TICKET): ?Ticket
    {
        $claims = $this->open($token);
        if (null === $claims || !self::validClaims($claims, $purpose)) {
            return null;
        }

        $ticket = $this->em->getRepository(Ticket::class)->find((int) $claims['ticket']);
        if (!$ticket instanceof Ticket || !$this->active($ticket)) {
            return null;
        }
        if ($ticket->getGuestAccessExpiresAt()?->getTimestamp() !== $claims['expires']
            || !hash_equals((string) $ticket->getGuestAccessHash(), hash('sha256', $claims['nonce']))
            || !hash_equals(hash('sha256', (string) $ticket->getGuestVerifiedEmail()), $claims['email'])
        ) {
            return null;
        }

        return $ticket;
    }

    /**
     * The widget's ticket routes: a grant for this reference, or a legacy
     * permanent token while those are accepted and no grant has replaced it.
     */
    public function ticketFor(string $reference, ?string $token): ?Ticket
    {
        if (null === $token || '' === $token) {
            return null;
        }

        $ticket = $this->resolve($token);
        if (null !== $ticket) {
            return hash_equals($ticket->getReference(), $reference) ? $ticket : null;
        }

        return $this->legacyTicket($reference, $token);
    }

    public function legacyTicket(string $reference, string $token): ?Ticket
    {
        if (!$this->acceptLegacyTokens || 1 !== preg_match('/^[0-9A-Za-z_-]{1,64}$/D', $token)) {
            return null;
        }

        $ticket = $this->em->getRepository(Ticket::class)->findOneBy(['reference' => $reference]);
        if (!$ticket instanceof Ticket || null !== $ticket->getGuestAccessHash() || null === $ticket->getGuestToken()) {
            return null;
        }

        return hash_equals($ticket->getGuestToken(), $token) ? $ticket : null;
    }

    public function revoke(Ticket $ticket): void
    {
        $ticket->setGuestAccess(null, null, null, null);
        $this->em->flush();
    }

    public function active(Ticket $ticket): bool
    {
        $expires = $ticket->getGuestAccessExpiresAt();

        return $this->verified($ticket)
            && 64 === \strlen((string) $ticket->getGuestAccessHash())
            && null !== $expires && $expires > new \DateTimeImmutable();
    }

    public function verified(Ticket $ticket): bool
    {
        return null !== $ticket->getGuestEmailVerifiedAt()
            && null !== $ticket->getGuestEmail()
            && $ticket->getGuestVerifiedEmail() === GuestEmailVerification::normalize($ticket->getGuestEmail());
    }

    /** Grant lifetime in minutes, clamped to 5 minutes through 7 days. */
    public function ttlMinutes(): int
    {
        return max(5, min(10_080, $this->ttlMinutes));
    }

    /** @param array<string, mixed> $claims */
    private static function validClaims(array $claims, string $purpose): bool
    {
        return self::VERSION === ($claims['v'] ?? null)
            && $purpose === ($claims['purpose'] ?? null)
            && \is_string($claims['ticket'] ?? null) && ctype_digit($claims['ticket'])
            && \is_string($claims['nonce'] ?? null) && 64 === \strlen($claims['nonce'])
            && \is_string($claims['email'] ?? null)
            && \is_int($claims['expires'] ?? null) && $claims['expires'] > time();
    }

    /** @param array<string, mixed> $claims */
    private function seal(array $claims): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(json_encode($claims, \JSON_THROW_ON_ERROR), self::CIPHER, $this->key(), \OPENSSL_RAW_DATA, $iv, $tag, 'escalated-guest-access');
        if (false === $ciphertext) {
            throw new \RuntimeException('Could not issue a guest grant.');
        }

        return rtrim(strtr(base64_encode($iv.$tag.$ciphertext), '+/', '-_'), '=');
    }

    /** @return array<string, mixed>|null */
    private function open(?string $token): ?array
    {
        if (null === $token || '' === $token || \strlen($token) > 4096 || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $token)) {
            return null;
        }

        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if (false === $raw || \strlen($raw) < 29) {
            return null;
        }

        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key(), \OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'escalated-guest-access');
        if (false === $plain) {
            return null;
        }

        try {
            $claims = json_decode($plain, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($claims) ? $claims : null;
    }

    private function key(): string
    {
        return hash_hkdf('sha256', $this->secret, 32, 'escalated guest access grant');
    }
}
