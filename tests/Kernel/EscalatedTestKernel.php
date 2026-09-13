<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Escalated\Symfony\EscalatedBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
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
     */
    public function __construct(
        string $environment,
        bool $debug,
        private readonly array $escalatedConfig = [],
        private readonly array $extensionConfig = [],
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

    private function varDir(): string
    {
        // One compiled container per configuration, or a test that disables
        // the UI would silently reuse the container another test compiled.
        $hash = substr(hash('xxh128', serialize([$this->escalatedConfig, $this->extensionConfig])), 0, 16);

        return sys_get_temp_dir().'/escalated-symfony-tests/'.$hash;
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
        ]);

        $container->extension('security', [
            'providers' => ['in_memory' => ['memory' => null]],
            'firewalls' => ['main' => ['lazy' => true]],
        ]);

        $orm = [
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'report_fields_where_declared' => true,
        ];
        if (\PHP_VERSION_ID >= 80400) {
            $orm['enable_native_lazy_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => ['url' => 'sqlite:///:memory:'],
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
