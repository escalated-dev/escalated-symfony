<?php

declare(strict_types=1);

namespace Escalated\Symfony\Service;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Escalated\Symfony\Entity\Ticket;

/**
 * Describes the Doctrine connections for Admin/Settings/DatabaseConnection,
 * as escalated-laravel's ConnectionInspector: which one Escalated's tables
 * live on, whether each is reachable and carries those tables, and how many
 * tickets it holds.
 *
 * Read-only. Escalated's connection is the one behind the `entity_manager`
 * bundle option, so it is always set in configuration and never switched
 * from the panel.
 */
class ConnectionInspector
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
    ) {
    }

    /**
     * Every configured connection, by name.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $names = array_keys($this->registry->getConnectionNames());
        sort($names);

        return array_map(fn (string $name): array => $this->describe($name), $names);
    }

    public function has(string $name): bool
    {
        return \array_key_exists($name, $this->registry->getConnectionNames());
    }

    /**
     * The name of the connection Escalated's entity manager uses.
     */
    public function current(): ?string
    {
        $connection = $this->em->getConnection();
        foreach (array_keys($this->registry->getConnectionNames()) as $name) {
            if ($this->registry->getConnection($name) === $connection) {
                return $name;
            }
        }

        return null;
    }

    public function hostDefault(): string
    {
        return $this->registry->getDefaultConnectionName();
    }

    /**
     * @return array{name: string, driver: ?string, database: ?string, is_current: bool, is_host_default: bool, reachable: bool, migrated: bool, ticket_count: ?int, error: ?string}
     */
    public function describe(string $name): array
    {
        $description = [
            'name' => $name,
            'driver' => null,
            'database' => null,
            'is_current' => $name === $this->current(),
            'is_host_default' => $name === $this->hostDefault(),
            'reachable' => false,
            'migrated' => false,
            'ticket_count' => null,
            'error' => null,
        ];

        try {
            $connection = $this->registry->getConnection($name);
            \assert($connection instanceof Connection);
            $params = $connection->getParams();
            $description['driver'] = self::driver($params);
            $description['database'] = self::databaseLabel($params, $description['driver']);

            $table = $this->em->getClassMetadata(Ticket::class)->getTableName();
            $description['migrated'] = $connection->createSchemaManager()->tablesExist([$table]);
            $description['reachable'] = true;
            if ($description['migrated']) {
                $description['ticket_count'] = (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$connection->quoteIdentifier($table));
            }
        } catch (\Throwable $e) {
            // Shown to an admin deciding why a database is not usable; the
            // panel is admin-only.
            $description['reachable'] = false;
            $description['migrated'] = false;
            $description['ticket_count'] = null;
            $description['error'] = $e->getMessage();
        }

        return $description;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function driver(array $params): ?string
    {
        $driver = $params['driver'] ?? null;
        if (!\is_string($driver) || '' === $driver) {
            return null;
        }

        return match ($driver) {
            'pdo_sqlite', 'sqlite3' => 'sqlite',
            'pdo_mysql', 'mysqli' => 'mysql',
            'pdo_pgsql', 'pgsql' => 'pgsql',
            'pdo_sqlsrv', 'sqlsrv' => 'sqlsrv',
            default => $driver,
        };
    }

    /**
     * A label without credentials: the SQLite path, or the database name and
     * host.
     *
     * @param array<string, mixed> $params
     */
    private static function databaseLabel(array $params, ?string $driver): ?string
    {
        if ('sqlite' === $driver) {
            if (true === ($params['memory'] ?? false)) {
                return ':memory:';
            }
            $path = $params['path'] ?? null;

            return \is_string($path) && '' !== $path ? $path : null;
        }

        $database = $params['dbname'] ?? null;
        if (!\is_string($database) || '' === $database) {
            return null;
        }
        $host = $params['host'] ?? null;

        return \is_string($host) && '' !== $host ? $database.' @ '.$host : $database;
    }
}
