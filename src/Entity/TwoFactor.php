<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Doctrine\UserIdType;
use Symfony\Component\Serializer\Attribute\Ignore;

/**
 * A user's TOTP enrolment. Pending until confirmed with a code
 * (`isEnabled`, `verifiedAt`). The secret and the recovery codes are stored
 * sealed (Security\SecretSealer); Service\TwoFactorService seals and opens
 * them, so this entity only ever holds ciphertext.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_two_factors')]
#[ORM\Index(columns: ['user_id'], name: 'idx_two_factor_user')]
#[ORM\HasLifecycleCallbacks]
class TwoFactor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: UserIdType::NAME)]
    private int|string $userId = 0;

    #[ORM\Column(type: Types::STRING, length: 32, options: ['default' => 'totp'])]
    private string $method = 'totp';

    #[ORM\Column(name: 'secret', type: Types::STRING, length: 255, nullable: true)]
    private ?string $sealedSecret = null;

    #[ORM\Column(name: 'recovery_codes', type: Types::TEXT, nullable: true)]
    private ?string $sealedRecoveryCodes = null;

    /** Confirmed: the user proved the secret with a code. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isEnabled = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    /** The TOTP time step of the last code accepted; it and earlier steps are refused. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private int|string|null $lastUsedStep = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int|string
    {
        return $this->userId;
    }

    public function setUserId(int|string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /** The sealed TOTP seed; never serialized. */
    #[Ignore]
    public function getSealedSecret(): ?string
    {
        return $this->sealedSecret;
    }

    public function setSealedSecret(?string $sealedSecret): self
    {
        $this->sealedSecret = $sealedSecret;

        return $this;
    }

    /** The sealed JSON list of recovery codes; never serialized. */
    #[Ignore]
    public function getSealedRecoveryCodes(): ?string
    {
        return $this->sealedRecoveryCodes;
    }

    public function setSealedRecoveryCodes(?string $sealedRecoveryCodes): self
    {
        $this->sealedRecoveryCodes = $sealedRecoveryCodes;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): self
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    public function isConfirmed(): bool
    {
        return $this->isEnabled;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeImmutable $verifiedAt): self
    {
        $this->verifiedAt = $verifiedAt;

        return $this;
    }

    #[Ignore]
    public function getLastUsedStep(): ?int
    {
        return null === $this->lastUsedStep ? null : (int) $this->lastUsedStep;
    }

    public function setLastUsedStep(?int $step): self
    {
        $this->lastUsedStep = $step;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
