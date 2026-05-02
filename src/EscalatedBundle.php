<?php

declare(strict_types=1);

namespace Escalated\Symfony;

use Escalated\Symfony\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class EscalatedBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        // AbstractBundle::configure() needs a root node definition to recognise
        // options. Delegate to the shared Configuration class by appending its
        // children onto our root node so there's a single source of truth.
        $source = (new Configuration())->getConfigTreeBuilder()->getRootNode();

        $reflection = new \ReflectionClass($source);
        $childrenProperty = $reflection->getProperty('children');
        $childrenProperty->setAccessible(true);
        /** @var array<string, \Symfony\Component\Config\Definition\Builder\NodeDefinition> $children */
        $children = $childrenProperty->getValue($source);

        $root = $definition->rootNode();
        foreach ($children as $child) {
            $root->append($child);
        }
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');

        // Store resolved config as parameters so services can access them
        $builder->setParameter('escalated.user_class', $config['user_class']);
        $builder->setParameter('escalated.route_prefix', $config['route_prefix']);
        $builder->setParameter('escalated.ui_enabled', $config['ui_enabled']);
        $builder->setParameter('escalated.table_prefix', $config['table_prefix']);

        // Storage config
        $builder->setParameter('escalated.storage.base_url', $config['storage']['base_url'] ?? '');

        // SLA config
        $builder->setParameter('escalated.sla.enabled', $config['sla']['enabled']);
        $builder->setParameter('escalated.sla.business_hours_only', $config['sla']['business_hours_only']);
        $builder->setParameter('escalated.sla.business_hours', $config['sla']['business_hours']);

        // Ticket config
        $builder->setParameter('escalated.tickets.allow_customer_close', $config['tickets']['allow_customer_close']);
        $builder->setParameter('escalated.tickets.default_priority', $config['tickets']['default_priority']);

        // Conditionally load web routes only when UI is enabled
        if ($config['ui_enabled']) {
            $container->import('../config/routes.yaml');
        }
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Prepend Doctrine mapping configuration for Escalated entities
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'Escalated' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => __DIR__.'/Entity',
                        'prefix' => 'Escalated\\Symfony\\Entity',
                        'alias' => 'Escalated',
                    ],
                ],
            ],
        ]);

        // Register the central escalated-dev/locale translations as a lower-priority
        // path so the plugin-local translations/ directory (and the app's own
        // translations/) can override individual keys. Symfony's translator
        // resolves later-registered paths with higher priority, so we list the
        // central package first and the plugin-local overrides second.
        $centralLocaleDir = $this->locateCentralLocaleDir();
        if ($centralLocaleDir !== null) {
            $builder->prependExtensionConfig('framework', [
                'translator' => [
                    'paths' => [
                        $centralLocaleDir,
                        __DIR__.'/../translations',
                    ],
                ],
            ]);
        }
    }

    /**
     * Resolve the translations directory shipped by the escalated-dev/locale
     * Composer package. Returns null when the package is not installed (e.g.
     * during bundle-only tests) so the host app continues to boot.
     */
    private function locateCentralLocaleDir(): ?string
    {
        // Walk up from this file until we find a vendor/ directory containing
        // the central locale package. This avoids hard-coding a relative path
        // and keeps the bundle usable when symlinked into a host app.
        $candidates = [
            __DIR__.'/../vendor/escalated-dev/locale/translations',
            __DIR__.'/../../../escalated-dev/locale/translations',
        ];
        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
        }

        return null;
    }
}
