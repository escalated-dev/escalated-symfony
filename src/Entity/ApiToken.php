<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * ApiToken — a hashed, per-user bearer credential for programmatic access to
 * the REST API. The plaintext token is shown once at creation and never
 * stored; only its SHA-256 hash is persisted.
 *
 * Mirrors the escalated_api_tokens table / ApiToken model in
 * escalated-laravel (abilities, last_used_at, expires_at). The Laravel model
 * is polymorphic (tokenable morph); here the token is bound to a single host
 * user id via the same convention as AgentCapacity / TwoFactor.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_api_tokens')]
#[ORM\Index(columns: ['user_id'], name: 'idx_api_token_user')]
#[ORM\UniqueConstraint(name: 'uniq_escalated_api_token', columns: ['token'])]
#[ORM\HasLifecycleCallbacks]
class ApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'user_id', type: Types::STRING, length: 255)]
    private string $userId = '';

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name = '';

    /** SHA-256 hash of the plaintext token (64 hex chars). */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $token = '';

    /** @var string[]|null Allowed scopes: any of 'agent', 'admin', '*'. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $abilities = null;

    #[ORM\Column(name: 'last_used_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'last_used_ip', type: Types::STRING, length: 45, nullable: true)]
    private ?string $lastUsedIp = null;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

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

    /**
     * True when the token grants the given ability (or holds the '*' wildcard).
     * Mirrors ApiToken::hasAbility in escalated-laravel.
     */
    public function hasAbility(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    /**
     * True when the token has an expiry that is in the past.
     * A null expiry means the token never expires.
     */
    public function isExpired(): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return $this->expiresAt <= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    /** @return string[]|null */
    public function getAbilities(): ?array
    {
        return $this->abilities;
    }

    /** @param string[]|null $abilities */
    public function setAbilities(?array $abilities): self
    {
        $this->abilities = $abilities;

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): self
    {
        $this->lastUsedAt = $lastUsedAt;

        return $this;
    }

    public function getLastUsedIp(): ?string
    {
        return $this->lastUsedIp;
    }

    public function setLastUsedIp(?string $lastUsedIp): self
    {
        $this->lastUsedIp = $lastUsedIp;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

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
