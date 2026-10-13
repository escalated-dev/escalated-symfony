<?php

declare(strict_types=1);

namespace Escalated\Symfony\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Escalated\Symfony\Entity\Ticket;

#[AsDoctrineListener(event: Events::loadClassMetadata)]
final class TicketAccessMetadata
{
    public function __construct(private readonly string $tablePrefix = 'escalated_')
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $tablePrefix)) {
            throw new \InvalidArgumentException('Invalid Escalated table prefix.');
        }
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $meta = $event->getClassMetadata();
        if (!str_starts_with($meta->name, 'Escalated\\Symfony\\Entity\\')) {
            return;
        }
        $replace = fn (string $table) => str_starts_with($table, 'escalated_') ? $this->tablePrefix.substr($table, 10) : $table;
        $meta->setPrimaryTable(['name' => $replace($meta->getTableName())]);
        foreach ($meta->associationMappings as $field => $mapping) {
            if (isset($mapping['joinTable'])) {
                $joinTable = $mapping['joinTable'];
                $joinTable['name'] = $replace($joinTable['name']);
                if (is_array($mapping)) {
                    $mapping['joinTable'] = $joinTable;
                }
                $meta->associationMappings[$field] = $mapping;
            }
        }
        if (Ticket::class !== $meta->name) {
            return;
        }
        $platform = $event->getObjectManager()->getConnection()->getDatabasePlatform();
        $collation = $platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform ? 'C' : ($platform instanceof \Doctrine\DBAL\Platforms\AbstractMySQLPlatform ? 'utf8mb4_bin' : 'BINARY');
        foreach (['accountType', 'accountId'] as $field) {
            $mapping = $meta->fieldMappings[$field];
            $mapping['options'] = ['collation' => $collation];
            $meta->fieldMappings[$field] = $mapping;
        }
    }
}
