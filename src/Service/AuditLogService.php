<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AuditLog;

class AuditLogService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?string $performerType = null,
        ?int $performerId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        $entry = new AuditLog();
        $entry->setAction($action);
        $entry->setEntityType($entityType);
        $entry->setEntityId($entityId);
        $entry->setPerformerType($performerType);
        $entry->setPerformerId($performerId);
        $entry->setOldValues($oldValues);
        $entry->setNewValues($newValues);
        $entry->setIpAddress($ipAddress);
        $entry->setUserAgent($userAgent);

        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    public function getLogsForEntity(string $entityType, int $entityId, int $limit = 50): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM escalated_audit_logs WHERE entity_type = ? AND entity_id = ? ORDER BY created_at DESC LIMIT ?',
            [$entityType, $entityId, $limit]
        );
    }

    public function getLogsByPerformer(string $performerType, int $performerId, int $limit = 50): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT * FROM escalated_audit_logs WHERE performer_type = ? AND performer_id = ? ORDER BY created_at DESC LIMIT ?',
            [$performerType, $performerId, $limit]
        );
    }
}
