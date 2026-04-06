<?php

declare(strict_types=1);

namespace Escalated\Symfony;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class EscalatedBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');

        // Store resolved config as parameters so services can access them
        $builder->setParameter('escalated.user_class', $config['user_class']);
        $builder->setParameter('escalated.route_prefix', $config['route_prefix']);
        $builder->setParameter('escalated.ui_enabled', $config['ui_enabled']);
        $builder->setParameter('escalated.table_prefix', $config['table_prefix']);

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
                        'dir' => __DIR__ . '/Entity',
                        'prefix' => 'Escalated\\Symfony\\Entity',
                        'alias' => 'Escalated',
                    ],
                ],
            ],
        ]);
    }
}
