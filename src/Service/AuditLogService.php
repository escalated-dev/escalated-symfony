<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\ORM\EntityManagerInterface;
use Escalated\Symfony\Entity\AuditLog;

class AuditLogService
{
    private const REDACTED_VALUE = '[REDACTED]';

    private const SENSITIVE_KEY_FRAGMENTS = [
        'api_key',
        'apikey',
        'authorization',
        'credential',
        'password',
        'recovery_code',
        'secret',
        'token',
    ];

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
        $entry->setOldValues($this->redactAuditValues($oldValues));
        $entry->setNewValues($this->redactAuditValues($newValues));
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

    private function redactAuditValues(?array $values): ?array
    {
        if (null === $values) {
            return null;
        }

        $redacted = $values;
        $this->redactArray($redacted);

        return $redacted;
    }

    /**
     * @param array<mixed> $values
     */
    private function redactArray(array &$values): void
    {
        foreach ($values as $key => &$value) {
            if (\is_string($key) && $this->isSensitiveKey($key)) {
                $value = self::REDACTED_VALUE;
                continue;
            }

            if (\is_array($value)) {
                $this->redactArray($value);
            }
        }

        unset($value);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = str_replace('-', '_', strtolower($key));

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
