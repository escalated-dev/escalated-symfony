<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Migrations;

use Doctrine\Migrations\DependencyFactory;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * A host that installs the bundle and runs `doctrine:migrations:migrate` must
 * end up with the schema the bundle's entities map -- on whichever database it
 * uses.
 *
 * It did not:
 *  - only one of the shipped migrations was discoverable, because one used the
 *    `Escalated\Symfony\Migrations` namespace and the rest `DoctrineMigrations`,
 *    and a migrations path maps one namespace;
 *  - most of them were raw MySQL (AUTO_INCREMENT, inline COMMENT and INDEX,
 *    ENGINE = InnoDB), which SQLite and PostgreSQL reject.
 *
 * Runs against SQLite in memory by default; CI repeats it on PostgreSQL and
 * MySQL through ESCALATED_TEST_DATABASE_URL.
 */
final class MigrationsTest extends EscalatedKernelTestCase
{
    private ApplicationTester $console;

    protected function setUp(): void
    {
        // No fixture host user: bundle migrations create the bundle's tables,
        // and schema:validate compares against every mapped entity.
        self::bootKernel(['host_user' => false]);

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $this->console = new ApplicationTester($application);

        // Start from an empty database, whichever one the run points at.
        $this->console(['command' => 'doctrine:schema:drop', '--full-database' => true, '--force' => true]);
    }

    public function testEveryShippedMigrationIsDiscovered(): void
    {
        $shipped = array_map(
            static fn (string $file): string => basename($file, '.php'),
            glob(\dirname(__DIR__, 3).'/migrations/Version*.php') ?: [],
        );
        sort($shipped);

        $discovered = [];
        foreach ($this->dependencyFactory()->getMigrationRepository()->getMigrations()->getItems() as $migration) {
            $version = (string) $migration->getVersion();
            $discovered[] = substr($version, (int) strrpos($version, '\\') + 1);
        }
        sort($discovered);

        self::assertNotEmpty($shipped);
        self::assertSame($shipped, $discovered, 'every file in migrations/ must be registered with Doctrine Migrations');
    }

    public function testMigratingAnEmptyDatabaseProducesTheMappedSchema(): void
    {
        $exitCode = $this->console(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        self::assertSame(0, $exitCode, "doctrine:migrations:migrate failed:\n".$this->console->getDisplay());

        $exitCode = $this->console(['command' => 'doctrine:schema:validate']);
        $validation = $this->console->getDisplay();

        if (0 !== $exitCode) {
            $this->console(['command' => 'doctrine:schema:update', '--dump-sql' => true]);
            $validation .= "\nDifference between the migrated database and the mapping:\n".$this->console->getDisplay();
        }

        self::assertSame(0, $exitCode, "doctrine:schema:validate failed:\n".$validation);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(array $input): int
    {
        return $this->console->run($input, ['interactive' => false, 'capture_stderr_separately' => false]);
    }

    private function dependencyFactory(): DependencyFactory
    {
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $factory);

        return $factory;
    }
}
