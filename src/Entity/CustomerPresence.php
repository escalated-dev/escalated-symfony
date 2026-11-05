<?php

declare(strict_types=1);

namespace Escalated\Symfony\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When a customer (a requester, or a guest's contact) was last seen by the
 * customer portal or the widget (ADR 2026-11-05-agent-panel-data). Only staff
 * pages ever show it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'escalated_customer_presence')]
#[ORM\UniqueConstraint(columns: ['subject_type', 'subject_id'])]
class CustomerPresence
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 191)]
        private string $subjectType,
        /** A string, so it holds integer, UUID and string keys alike. */
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $subjectId,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $lastSeenAt,
    ) {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
