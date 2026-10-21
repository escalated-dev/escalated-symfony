<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A pending proof that a guest controls a mailbox: an emailed eight-digit
 * code, stored only as keyed digests, bound to the normalised email and one
 * purpose. See {@see \Escalated\Symfony\Security\GuestEmailVerification}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_guest_verifications')]
#[ORM\Index(columns: ['expires_at'], name: 'idx_guest_verification_expires')]
class GuestVerification
{
    public const PURPOSE_TICKET = 'ticket';
    public const PURPOSE_CHAT = 'chat';
    public const PURPOSE_LOOKUP = 'lookup';

    public const PURPOSES = [self::PURPOSE_TICKET, self::PURPOSE_CHAT, self::PURPOSE_LOOKUP];

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $emailDigest;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $purpose;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $codeHash;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, string $emailDigest, string $purpose, string $codeHash, \DateTimeImmutable $expiresAt)
    {
        $this->id = $id;
        $this->emailDigest = $emailDigest;
        $this->purpose = $purpose;
        $this->codeHash = $codeHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmailDigest(): string
    {
        return $this->emailDigest;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getCodeHash(): string
    {
        return $this->codeHash;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function recordAttempt(): void
    {
        ++$this->attempts;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
