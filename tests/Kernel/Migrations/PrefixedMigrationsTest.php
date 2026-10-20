<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Migrations;

use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * The entities honor `table_prefix`, so a host that sets one must get the
 * same prefixed tables from `doctrine:migrations:migrate`.
 *
 * It did not: every migration but the last hard-coded `escalated_*`, so a
 * fresh install with a custom prefix created unprefixed tables and then
 * failed on the one migration that looked up `{prefix}tickets`.
 */
final class PrefixedMigrationsTest extends EscalatedKernelTestCase
{
    public static function prefixes(): iterable
    {
        yield 'custom' => ['support_'];
        yield 'mixed case' => ['Support_'];
    }

    #[DataProvider('prefixes')]
    public function testTheFullMigrationSetHonorsACustomTablePrefix(string $prefix): void
    {
        self::bootKernel(['host_user' => false, 'escalated' => ['table_prefix' => $prefix]]);

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $console = new ApplicationTester($application);
        $run = static fn (array $input): int => $console->run($input, ['interactive' => false, 'capture_stderr_separately' => false]);

        $run(['command' => 'doctrine:schema:drop', '--full-database' => true, '--force' => true]);

        $exitCode = $run(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        self::assertSame(0, $exitCode, "doctrine:migrations:migrate failed:\n".$console->getDisplay());

        $tables = array_map(strtolower(...), self::entityManager()->getConnection()->createSchemaManager()->listTableNames());
        self::assertContains(strtolower($prefix).'tickets', $tables);
        self::assertContains(strtolower($prefix).'chat_sessions', $tables);
        self::assertSame(
            [],
            array_values(array_filter($tables, static fn (string $table): bool => str_starts_with($table, 'escalated_'))),
            'no unprefixed escalated_* table may be created',
        );

        // PostgreSQL folds unquoted identifiers, so a mixed-case prefix is a
        // lowercase one there and the implicit index names Doctrine hashes
        // from the mapped (mixed-case) table name cannot match. Use a
        // lowercase prefix on PostgreSQL.
        $platform = self::entityManager()->getConnection()->getDatabasePlatform();
        if ($platform instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform && strtolower($prefix) !== $prefix) {
            return;
        }

        // The migrated schema is the one the prefixed mapping describes:
        // generated index and foreign key names hash the real table name.
        $exitCode = $run(['command' => 'doctrine:schema:validate']);
        $validation = $console->getDisplay();
        if (0 !== $exitCode) {
            $run(['command' => 'doctrine:schema:update', '--dump-sql' => true]);
            $validation .= "\nDifference between the migrated database and the mapping:\n".$console->getDisplay();
        }
        self::assertSame(0, $exitCode, "doctrine:schema:validate failed:\n".$validation);
    }
}
