<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;

/**
 * Gets a guest email proof the way the shared widget does: POST
 * /verification, then read the eight-digit code from the email it sent.
 * Each request comes from its own address, so a test's own IP limits are
 * left untouched.
 */
trait ObtainsGuestProof
{
    /**
     * @return array{verification_id: string, verification_code: string}
     */
    protected static function guestProof(KernelBrowser $client, string $email, string $purpose = 'ticket', string $api = '/support/widget/api'): array
    {
        $proof = self::requestGuestProof($client, $email, $purpose, $api);
        self::assertNotNull($proof, (string) $client->getResponse()->getContent());

        return $proof;
    }

    /**
     * @return array{verification_id: string, verification_code: string}|null null when no code was sent
     */
    private static function requestGuestProof(KernelBrowser $client, string $email, string $purpose, string $api): ?array
    {
        $client->request('POST', $api.'/verification', server: [
            'REMOTE_ADDR' => sprintf('2001:db8:feed::%x:%x', random_int(1, 0xFFFF), random_int(1, 0xFFFF)),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: (string) json_encode(['email' => $email, 'purpose' => $purpose]));

        $response = $client->getResponse();
        $id = json_decode((string) $response->getContent(), true)['verification_id'] ?? null;
        if (202 !== $response->getStatusCode() || !\is_string($id)) {
            return null;
        }

        return ['verification_id' => $id, 'verification_code' => self::lastCodeSentTo($email)];
    }

    /**
     * A widget ticket or chat start body with a proof for its email added,
     * as the shared widget sends it. Other requests, and bodies with no
     * email or a proof already, are returned unchanged.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>|null
     */
    protected static function withGuestProof(KernelBrowser $client, string $method, string $path, ?array $body): ?array
    {
        $purpose = match (true) {
            'POST' !== $method, null === $body, isset($body['verification_id']) => null,
            str_ends_with($path, '/tickets') => 'ticket',
            str_ends_with($path, '/chat/start') => 'chat',
            default => null,
        };
        $email = $body['email'] ?? $body['guest_email'] ?? null;
        if (null === $purpose || !\is_string($email) || false === filter_var(trim($email), \FILTER_VALIDATE_EMAIL)) {
            return $body;
        }

        // When no code can be sent (guest tickets off, say), the request goes
        // out without one and meets the same refusal.
        return $body + (self::requestGuestProof($client, $email, $purpose, substr($path, 0, (int) strrpos($path, str_ends_with($path, '/chat/start') ? '/chat/start' : '/tickets'))) ?? []);
    }

    protected static function lastCodeSentTo(string $email): string
    {
        foreach (array_reverse(self::getMailerMessages()) as $message) {
            if (!$message instanceof Email) {
                continue;
            }
            foreach ($message->getTo() as $address) {
                if (strtolower($address->getAddress()) === strtolower(trim($email))
                    && 1 === preg_match('/\b(\d{8})\b/', (string) $message->getTextBody(), $m)
                ) {
                    return $m[1];
                }
            }
        }

        self::fail('No verification code was emailed to '.$email);
    }
}
