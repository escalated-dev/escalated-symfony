<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Escalated\Symfony\EscalatedBundle;
use Escalated\Symfony\Tests\Kernel\Fixtures\Entity\TestUser;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * A minimal host application: the bundles a real Symfony host has, the
 * bundle's configuration passed in by the test, and the bundle's routes
 * imported the way the README tells a host to import them.
 *
 * Everything the unit tests mock away -- config processing, service loading,
 * autowiring, route loading -- runs for real here, so a wiring fault that only
 * shows when the bundle is installed fails a test instead of a host.
 */
final class EscalatedTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $escalatedConfig the `escalated:` configuration block
     * @param array<string, mixed> $extensionConfig extra extension configs, keyed by extension name
     * @param bool                 $withHostUser    map the fixture host user entity and use it as the
     *                                              firewall's user provider
     */
    public function __construct(
        string $environment,
        bool $debug,
        private readonly array $escalatedConfig = [],
        private readonly array $extensionConfig = [],
        private readonly bool $withHostUser = true,
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SecurityBundle();
        yield new TwigBundle();
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new EscalatedBundle();
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return $this->varDir().'/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->varDir().'/log';
    }

    public function boot(): void
    {
        // A non-debug kernel never checks its compiled container for freshness,
        // so a container compiled by an earlier PHPUnit run would hide every
        // change to config/services.yaml or the bundle extension since -- a
        // wiring test would pass against wiring that no longer exists. Start
        // each PHPUnit process from an empty cache.
        if (!self::$cacheCleared) {
            (new Filesystem())->remove(self::cacheRoot());
            self::$cacheCleared = true;
        }

        parent::boot();
    }

    private static bool $cacheCleared = false;

    private static function cacheRoot(): string
    {
        // Keyed by checkout, so two clones or worktrees never share or wipe
        // each other's compiled containers.
        return sys_get_temp_dir().'/escalated-symfony-tests/'.substr(hash('xxh128', __DIR__), 0, 8);
    }

    /**
     * SQLite in memory unless ESCALATED_TEST_DATABASE_URL names another
     * database (CI runs the kernel tests against PostgreSQL and MySQL too).
     */
    public static function databaseUrl(): string
    {
        $url = $_SERVER['ESCALATED_TEST_DATABASE_URL'] ?? $_ENV['ESCALATED_TEST_DATABASE_URL'] ?? getenv('ESCALATED_TEST_DATABASE_URL');

        return \is_string($url) && '' !== $url ? $url : 'sqlite:///:memory:';
    }

    private function varDir(): string
    {
        // One compiled container per configuration, or a test that disables
        // the UI would silently reuse the container another test compiled.
        $hash = substr(hash('xxh128', serialize([
            $this->escalatedConfig,
            $this->extensionConfig,
            $this->withHostUser,
            self::databaseUrl(),
        ])), 0, 16);

        return self::cacheRoot().'/'.$hash;
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'escalated-test-secret',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'serializer' => ['enabled' => true],
            'mailer' => ['dsn' => 'null://null'],
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
        ]);

        // A stateful, lazy firewall over the whole application with a Doctrine
        // user provider -- the shape of a typical host's `main` firewall, and
        // the one Escalated's API has to work behind.
        $container->extension('security', $this->withHostUser
            ? [
                'providers' => [
                    'test_users' => ['entity' => ['class' => TestUser::class, 'property' => 'email']],
                ],
                'firewalls' => ['main' => ['lazy' => true, 'provider' => 'test_users']],
            ]
            : [
                'providers' => ['in_memory' => ['memory' => null]],
                'firewalls' => ['main' => ['lazy' => true, 'provider' => 'in_memory']],
            ]);

        $orm = [
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'report_fields_where_declared' => true,
        ];
        if ($this->withHostUser) {
            $orm['mappings'] = [
                'TestApp' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => __DIR__.'/Fixtures/Entity',
                    'prefix' => 'Escalated\Symfony\Tests\Kernel\Fixtures\Entity',
                    'alias' => 'TestApp',
                ],
            ];
        }
        if (\PHP_VERSION_ID >= 80400) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => ['url' => self::databaseUrl()],
            'orm' => $orm,
        ]);

        $container->extension('escalated', $this->escalatedConfig);

        foreach ($this->extensionConfig as $extension => $config) {
            $container->extension($extension, $config);
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('@EscalatedBundle/config/routes.yaml');
    }
}
