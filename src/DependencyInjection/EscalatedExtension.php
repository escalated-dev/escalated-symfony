<?php

declare(strict_types=1);

namespace Escalated\Symfony\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Traditional extension class for Symfony versions that don't support AbstractBundle::loadExtension().
 *
 * The primary configuration is handled by EscalatedBundle::loadExtension() for Symfony 6.4+.
 * This class is provided as a fallback for compatibility.
 */
class EscalatedExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');

        $container->setParameter('escalated.user_class', $config['user_class']);
        $container->setParameter('escalated.route_prefix', $config['route_prefix']);
        $container->setParameter('escalated.ui_enabled', $config['ui_enabled']);
        $container->setParameter('escalated.table_prefix', $config['table_prefix']);
        $container->setParameter('escalated.sla.enabled', $config['sla']['enabled']);
        $container->setParameter('escalated.sla.business_hours_only', $config['sla']['business_hours_only']);
        $container->setParameter('escalated.sla.business_hours', $config['sla']['business_hours']);
        $container->setParameter('escalated.tickets.allow_customer_close', $config['tickets']['allow_customer_close']);
        $container->setParameter('escalated.tickets.default_priority', $config['tickets']['default_priority']);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'Escalated' => [
                        'is_bundle' => false,
                        'type' => 'attribute',
                        'dir' => __DIR__.'/../Entity',
                        'prefix' => 'Escalated\\Symfony\\Entity',
                        'alias' => 'Escalated',
                    ],
                ],
            ],
        ]);
    }
}
