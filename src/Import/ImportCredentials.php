<?php

declare(strict_types=1);

namespace Escalated\Symfony\Import;

/**
 * Seals an import source's credentials for the two places they rest: the
 * wizard's session, from a tested connection until the import starts, and the
 * import job's `credentials` column until the run ends or is cancelled.
 * AES-256-GCM under a key derived from `kernel.secret`.
 */
class ImportCredentials
{
    private const CIPHER = 'aes-256-gcm';
    private const CONTEXT = 'escalated-import-credentials';

    public function __construct(private readonly string $secret)
    {
    }

    /** @param array<string, mixed> $credentials */
    public function seal(array $credentials): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(json_encode($credentials, \JSON_THROW_ON_ERROR), self::CIPHER, $this->key(), \OPENSSL_RAW_DATA, $iv, $tag, self::CONTEXT);
        if (false === $ciphertext) {
            throw new \RuntimeException('Could not encrypt the import credentials.');
        }

        return base64_encode($iv.$tag.$ciphertext);
    }

    /** @return array<string, mixed>|null null when absent, tampered with, or sealed under another secret */
    public function open(?string $sealed): ?array
    {
        if (null === $sealed || '' === $sealed) {
            return null;
        }
        $raw = base64_decode($sealed, true);
        if (false === $raw || \strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key(), \OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), self::CONTEXT);
        if (false === $plain) {
            return null;
        }

        try {
            $value = json_decode($plain, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($value) ? $value : null;
    }

    private function key(): string
    {
        return hash_hkdf('sha256', $this->secret, 32, self::CONTEXT);
    }
}
