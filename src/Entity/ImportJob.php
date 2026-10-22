<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Escalated\Symfony\Doctrine\UserIdType;
use Symfony\Component\Uid\Uuid;

/**
 * One import from an external platform, as escalated-laravel's ImportJob:
 * its status, per-entity progress and cursors, error log and (sealed by
 * {@see \Escalated\Symfony\Import\ImportCredentials}) the source credentials,
 * which are dropped when the import completes or is cancelled.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_import_jobs')]
#[ORM\Index(columns: ['status'], name: 'idx_import_job_status')]
#[ORM\Index(columns: ['user_id'], name: 'idx_import_job_user')]
#[ORM\HasLifecycleCallbacks]
class ImportJob
{
    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        'pending' => ['authenticating'],
        'authenticating' => ['mapping', 'failed'],
        'mapping' => ['importing', 'failed'],
        'importing' => ['paused', 'completed', 'failed'],
        'paused' => ['importing', 'failed'],
        'completed' => [],
        'failed' => ['mapping'],
    ];

    private const MAX_ERRORS = 10000;

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\Column(type: UserIdType::NAME, nullable: true)]
    private int|string|null $userId = null;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $platform;

    #[ORM\Column(type: Types::STRING, length: 30, options: ['default' => 'pending'])]
    private string $status = 'pending';

    /** Sealed JSON; see ImportCredentials. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $credentials = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $fieldMappings = null;

    /** @var array<string, array{total: int, processed: int, skipped: int, failed: int, cursor: string|null}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $progress = null;

    /** @var list<array{entity_type: string, source_id: string, error: string, timestamp: string}>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $errorLog = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $platform, string $status = 'pending')
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->platform = $platform;
        $this->status = $status;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): int|string|null
    {
        return $this->userId;
    }

    public function setUserId(int|string|null $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /** Moves along the state machine; throws on a transition it does not allow. */
    public function transitionTo(string $status): self
    {
        if (!\in_array($status, self::TRANSITIONS[$this->status] ?? [], true)) {
            throw new \InvalidArgumentException(sprintf("Cannot transition from '%s' to '%s'.", $this->status, $status));
        }

        $this->status = $status;

        return $this;
    }

    public function isResumable(): bool
    {
        return \in_array($this->status, ['paused', 'failed'], true);
    }

    public function getSealedCredentials(): ?string
    {
        return $this->credentials;
    }

    public function setSealedCredentials(?string $sealed): self
    {
        $this->credentials = $sealed;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getFieldMappings(): array
    {
        return $this->fieldMappings ?? [];
    }

    /** @param array<string, mixed>|null $fieldMappings */
    public function setFieldMappings(?array $fieldMappings): self
    {
        $this->fieldMappings = $fieldMappings;

        return $this;
    }

    /** @return array<string, array{total: int, processed: int, skipped: int, failed: int, cursor: string|null}> */
    public function getProgress(): array
    {
        return $this->progress ?? [];
    }

    /**
     * Merges counters for one entity type. A null cursor keeps the last one,
     * as Laravel's does: a resume re-reads one page, not the whole type.
     */
    public function updateEntityProgress(string $entityType, ?int $processed = null, ?int $total = null, ?int $skipped = null, ?int $failed = null, ?string $cursor = null): self
    {
        $progress = $this->progress ?? [];
        $current = $progress[$entityType] ?? ['total' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0, 'cursor' => null];

        foreach (['processed' => $processed, 'total' => $total, 'skipped' => $skipped, 'failed' => $failed, 'cursor' => $cursor] as $key => $value) {
            if (null !== $value) {
                $current[$key] = $value;
            }
        }

        $progress[$entityType] = $current;
        $this->progress = $progress;

        return $this;
    }

    public function getEntityCursor(string $entityType): ?string
    {
        return $this->progress[$entityType]['cursor'] ?? null;
    }

    /** @return list<array{entity_type: string, source_id: string, error: string, timestamp: string}> */
    public function getErrorLog(): array
    {
        return $this->errorLog ?? [];
    }

    /** @param list<array{entity_type: string, source_id: string, error: string, timestamp: string}>|null $errorLog */
    public function setErrorLog(?array $errorLog): self
    {
        $this->errorLog = $errorLog;

        return $this;
    }

    public function appendError(string $entityType, string $sourceId, string $error): self
    {
        $log = $this->errorLog ?? [];
        if (\count($log) < self::MAX_ERRORS) {
            $log[] = [
                'entity_type' => $entityType,
                'source_id' => $sourceId,
                'error' => $error,
                'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
            $this->errorLog = $log;
        }

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

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
