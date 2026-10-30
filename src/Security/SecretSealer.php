<?php

declare(strict_types=1);

namespace Escalated\Symfony\Security;

/**
 * Encrypts a value at rest with AES-256-GCM (OpenSSL) under a key derived
 * from `kernel.secret` for one purpose (the context), the scheme
 * ImportCredentials and GuestAccess already use. A value sealed for one
 * context does not open in another, and a tampered value does not open.
 */
class SecretSealer
{
    private const CIPHER = 'aes-256-gcm';

    public function __construct(private readonly string $secret)
    {
    }

    public function seal(string $plain, string $context): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plain, self::CIPHER, $this->key($context), \OPENSSL_RAW_DATA, $iv, $tag, $context);
        if (false === $ciphertext) {
            throw new \RuntimeException('Could not encrypt the value.');
        }

        return base64_encode($iv.$tag.$ciphertext);
    }

    /** null when absent, tampered with, sealed for another context or under another secret */
    public function open(?string $sealed, string $context): ?string
    {
        if (null === $sealed || '' === $sealed) {
            return null;
        }
        $raw = base64_decode($sealed, true);
        if (false === $raw || \strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, $this->key($context), \OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $context);

        return false === $plain ? null : $plain;
    }

    private function key(string $context): string
    {
        return hash_hkdf('sha256', $this->secret, 32, $context);
    }
}
