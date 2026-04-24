<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * First-class identity for guest requesters (Pattern B).
 *
 * Deduped by email (unique index; value is lowercased + trimmed on
 * set). Links to a host-app user via userId once the guest accepts
 * a signup invite.
 *
 * Coexists with the inline guest_* fields on Ticket for one release
 * — a follow-up pass backfills Ticket.contact from Ticket.guestEmail.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_contacts')]
#[ORM\Index(columns: ['user_id'], name: 'idx_contact_user')]
#[ORM\HasLifecycleCallbacks]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 320, unique: true)]
    private string $email = '';

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $name = null;

    /** Host-app user id once the contact creates an account. */
    #[ORM\Column(name: 'user_id', type: Types::INTEGER, nullable: true)]
    private ?int $userId = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ---------------------------------------------------------------------
    // Pure static helpers — used by the service layer without touching the
    // ORM, and verifiable in plain PHPUnit tests.
    // ---------------------------------------------------------------------

    public static function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string) $email));
    }

    /**
     * Returns 'create', 'update-name', or 'return-existing'.
     * Pure branch-selection logic for find_or_create_by_email.
     */
    public static function decideAction(?self $existing, ?string $incomingName): string
    {
        if ($existing === null) {
            return 'create';
        }
        if (($existing->getName() === null || $existing->getName() === '') && ! empty($incomingName)) {
            return 'update-name';
        }
        return 'return-existing';
    }

    // ---------------------------------------------------------------------
    // Getters / setters
    // ---------------------------------------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = self::normalizeEmail($email);
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /** @return array<string, mixed> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @param array<string, mixed> $metadata */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
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

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
