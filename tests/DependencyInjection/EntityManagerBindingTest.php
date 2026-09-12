<?php

declare(strict_types=1);

namespace Escalated\Symfony\Tests\DependencyInjection;

use Escalated\Symfony\DependencyInjection\Configuration;
use Escalated\Symfony\EscalatedBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Escalated's entities resolved the host's default Doctrine entity manager with
 * no way to change it, which made the bundle unusable in any host that
 * partitions its database.
 *
 * Sixty-two services in this bundle autowire EntityManagerInterface. If that
 * keeps resolving the default manager, every one of them writes to the primary
 * database no matter what the host configured -- and nothing errors, it just
 * silently uses the wrong database. These tests pin the alias and the bind that
 * prevent it.
 */
class EntityManagerBindingTest extends TestCase
{
    /** @param array<string, mixed> $config */
    private function build(array $config = []): ContainerBuilder
    {
        $builder = new ContainerBuilder();

        $processed = (new Processor())->processConfiguration(new Configuration(), [$config]);

        // Exercise the same private helper loadExtension() uses, without
        // standing up the whole bundle extension and its service imports.
        $bundle = new EscalatedBundle();
        $method = new \ReflectionMethod($bundle, 'aliasEntityManager');
        $method->setAccessible(true);
        $method->invoke($bundle, $builder, $processed['entity_manager'] ?? null);

        return $builder;
    }

    public function testDefaultsToNullSoAnUnconfiguredHostIsUnchanged(): void
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertNull($processed['entity_manager']);
    }

    public function testAliasesTheDefaultManagerWhenNothingIsConfigured(): void
    {
        $builder = $this->build();

        self::assertTrue($builder->hasAlias('escalated.entity_manager'));
        self::assertSame(
            'doctrine.orm.entity_manager',
            (string) $builder->getAlias('escalated.entity_manager'),
        );
    }

    public function testAliasesTheNamedManagerWhenOneIsConfigured(): void
    {
        $builder = $this->build(['entity_manager' => 'support']);

        self::assertSame(
            'doctrine.orm.support_entity_manager',
            (string) $builder->getAlias('escalated.entity_manager'),
        );
    }

    public function testTreatsAnEmptyManagerNameAsUnset(): void
    {
        // An empty string is what an unset environment variable resolves to,
        // and doctrine.orm._entity_manager is not a service.
        $builder = $this->build(['entity_manager' => '']);

        self::assertSame(
            'doctrine.orm.entity_manager',
            (string) $builder->getAlias('escalated.entity_manager'),
        );
    }

    /**
     * The bind is what carries the alias to all sixty-two call sites. Without
     * it the alias exists and nothing uses it, which is the failure that looks
     * like success.
     */
    public function testServicesBindTheEntityManagerTypeToEscalatedsManager(): void
    {
        // Read as text rather than parsed: symfony/yaml is not a dependency of
        // this package, and adding one for a single assertion would be a worse
        // trade than matching the line.
        $services = $this->servicesYaml();

        self::assertMatchesRegularExpression(
            '/^\s*Doctrine\\\\ORM\\\\EntityManagerInterface:\s*.@escalated\.entity_manager.\s*$/m',
            $services,
            'EntityManagerInterface must be bound by type, or autowired services silently reach the host default manager.',
        );
    }

    private function servicesYaml(): string
    {
        $path = __DIR__.'/../../config/services.yaml';
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Bound by type rather than by type + parameter name on purpose: a new
     * service that happens to name the argument something else must not fall
     * back to the default manager.
     */
    public function testTheBindIsByTypeAloneNotByParameterName(): void
    {
        $services = $this->servicesYaml();

        // Binding by parameter name would let a service that names the argument
        // anything else fall through to the host's default manager.
        self::assertDoesNotMatchRegularExpression(
            '/EntityManagerInterface\s+\$\w+\s*:/',
            $services,
            'Bind EntityManagerInterface by type alone, not by type and parameter name.',
        );
    }
}
