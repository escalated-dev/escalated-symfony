<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel\Migrations;

use Escalated\Symfony\Doctrine\Migration\BundleMigration;
use Escalated\Symfony\Tests\Kernel\EscalatedKernelTestCase;
use Escalated\Symfony\Tests\Kernel\EscalatedTestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * Twenty of the bundle's migrations used to be `DoctrineMigrations\VersionX`
 * and are now `Escalated\Symfony\Migrations\VersionX`. Doctrine records
 * executed migrations by class name, so an install that ran them under the old
 * name must not run them again -- their CREATE TABLEs would fail against the
 * tables they already made.
 */
final class LegacyMigrationNamesTest extends EscalatedKernelTestCase
{
    private const METADATA_TABLE = 'doctrine_migration_versions';

    private ?string $sqliteFile = null;

    protected function setUp(): void
    {
        // The two migrate runs happen in separate kernels, the way two
        // `bin/console` invocations would, so an in-memory database would not
        // survive between them.
        if ('sqlite:///:memory:' === EscalatedTestKernel::databaseUrl()) {
            $this->sqliteFile = sys_get_temp_dir().'/escalated-legacy-migrations-'.bin2hex(random_bytes(4)).'.sqlite';
            $_SERVER['ESCALATED_TEST_DATABASE_URL'] = 'sqlite:///'.$this->sqliteFile;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (null !== $this->sqliteFile) {
            unset($_SERVER['ESCALATED_TEST_DATABASE_URL']);
            @unlink($this->sqliteFile);
        }
    }

    public function testAnInstallThatRanTheOldClassNamesDoesNotRunThemAgain(): void
    {
        $console = $this->bootConsole();
        $this->console($console, ['command' => 'doctrine:schema:drop', '--full-database' => true, '--force' => true]);
        self::assertSame(0, $this->console($console, ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $console->getDisplay());

        // Rewrite the metadata into what an install that ran them under their
        // old names holds.
        $connection = self::entityManager()->getConnection();
        $renamed = [];
        foreach (glob(\dirname(__DIR__, 3).'/migrations/Version*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $class = 'Escalated\Symfony\Migrations\\'.$short;
            if (!class_exists($class, false) || !is_subclass_of($class, BundleMigration::class)) {
                continue;
            }
            $legacy = 'DoctrineMigrations\\'.$short;
            self::assertSame(1, $connection->update(self::METADATA_TABLE, ['version' => $legacy], ['version' => $class]));
            $renamed[$legacy] = $class;
        }
        self::assertCount(20, $renamed, 'twenty migrations moved out of the DoctrineMigrations namespace');

        // A fresh kernel: the upgrade runs in a new process.
        self::ensureKernelShutdown();
        $console = $this->bootConsole();

        $exitCode = $this->console($console, ['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
        $display = $console->getDisplay();

        self::assertSame(0, $exitCode, "migrating an install with the old names failed:\n".$display);

        $recorded = self::entityManager()->getConnection()->fetchFirstColumn('SELECT version FROM '.self::METADATA_TABLE);
        foreach ($renamed as $legacy => $class) {
            self::assertContains($class, $recorded, "$class should now be recorded as executed");
            self::assertContains($legacy, $recorded, "$legacy should be left in place");
        }

        self::assertSame(0, $this->console($console, ['command' => 'doctrine:schema:validate']), $console->getDisplay());
    }

    private function bootConsole(): ApplicationTester
    {
        self::bootKernel(['host_user' => false]);

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        return new ApplicationTester($application);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function console(ApplicationTester $console, array $input): int
    {
        return $console->run($input, ['interactive' => false]);
    }
}
