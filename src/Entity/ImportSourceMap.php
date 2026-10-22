<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which Escalated record a source record became, per import job. A record
 * already mapped is skipped, which is what makes an import resumable; later
 * entity types resolve their references (a reply's ticket) through it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_import_source_maps')]
#[ORM\UniqueConstraint(name: 'uniq_import_source_map', columns: ['import_job_id', 'entity_type', 'source_id'])]
#[ORM\Index(columns: ['import_job_id', 'entity_type'], name: 'idx_import_source_map_type')]
class ImportSourceMap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ImportJob::class)]
    #[ORM\JoinColumn(name: 'import_job_id', nullable: false, onDelete: 'CASCADE')]
    private ImportJob $importJob;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $entityType;

    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $sourceId;

    #[ORM\Column(type: Types::STRING, length: 191)]
    private string $escalatedId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(ImportJob $importJob, string $entityType, string $sourceId, string $escalatedId)
    {
        $this->importJob = $importJob;
        $this->entityType = $entityType;
        $this->sourceId = $sourceId;
        $this->escalatedId = $escalatedId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getImportJob(): ImportJob
    {
        return $this->importJob;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getEscalatedId(): string
    {
        return $this->escalatedId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
