<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\SsoMessageId;

/**
 * Single-use message IDs for SSO: the AuthnRequest IDs Escalated issued
 * (`saml_request`), and the SAML assertion IDs (`saml_assertion`) and JWT
 * `jti`s (`jwt`) it has accepted. Rows expire at `expires_at` (Unix seconds)
 * and are pruned on write. The unique (kind, message_id) index is what makes
 * claim() single-use, also across concurrent requests.
 */
class SsoMessageIds
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Store an ID that a later message must name (an issued request). */
    public function remember(string $kind, string $id, int $expiresAt): void
    {
        $this->prune();

        $this->em->getConnection()->insert($this->table(), ['kind' => $kind, 'message_id' => $id, 'expires_at' => $expiresAt]);
    }

    /** Remove an unexpired remembered ID: true only the first time. */
    public function consume(string $kind, string $id): bool
    {
        return 1 === (int) $this->em->getConnection()->executeStatement(
            'DELETE FROM '.$this->table().' WHERE kind = ? AND message_id = ? AND expires_at > ?',
            [$kind, $id, time()],
        );
    }

    /** Record an ID as used: true the first time, false on a replay. */
    public function claim(string $kind, string $id, int $expiresAt): bool
    {
        $this->prune();

        try {
            $this->em->getConnection()->insert($this->table(), ['kind' => $kind, 'message_id' => $id, 'expires_at' => $expiresAt]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function prune(): void
    {
        $this->em->getConnection()->executeStatement('DELETE FROM '.$this->table().' WHERE expires_at <= ?', [time()]);
    }

    private function table(): string
    {
        return $this->em->getClassMetadata(SsoMessageId::class)->getTableName();
    }
}
