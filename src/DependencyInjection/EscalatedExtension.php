<?php

declare(strict_types=1);

namespace Escalated\Symfony\DependencyInjection;

use Escalated\Symfony\Doctrine\UserIdType;
use Escalated\Symfony\EscalatedBundle;
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
        EscalatedBundle::setTicketAccess($container, $config);
        $container->setAlias('escalated.entity_manager', empty($config['entity_manager']) ? 'doctrine.orm.entity_manager' : 'doctrine.orm.'.$config['entity_manager'].'_entity_manager');

        $container->setParameter('escalated.user_class', $config['user_class']);
        $container->setParameter('escalated.route_prefix', $config['route_prefix']);
        $container->setParameter('escalated.ui_enabled', $config['ui_enabled']);
        $container->setParameter('escalated.enable_newsletters', $config['enable_newsletters']);
        $container->setParameter('escalated.table_prefix', $config['table_prefix']);
        foreach ($config['newsletters'] as $key => $value) {
            $container->setParameter('escalated.newsletters.'.$key, $value);
        }
        EscalatedBundle::setGuestRateLimit($container, $config['guest_rate_limit']);
        EscalatedBundle::setGuestAccess($container, $config['guest_access']);
        $container->setParameter('escalated.sla.enabled', $config['sla']['enabled']);
        $container->setParameter('escalated.sla.business_hours_only', $config['sla']['business_hours_only']);
        $container->setParameter('escalated.sla.business_hours', $config['sla']['business_hours']);
        $container->setParameter('escalated.storage.base_url', $config['storage']['base_url']);
        $container->setParameter('escalated.tickets.allow_customer_close', $config['tickets']['allow_customer_close']);
        $container->setParameter('escalated.tickets.default_priority', $config['tickets']['default_priority']);
        $container->setParameter('escalated.ticket_subjects.types', $config['ticket_subjects']['types'] ?? []);
        $resolverId = $config['ticket_subjects']['resolver'] ?? null;
        $container->setParameter('escalated.ticket_subjects.resolver', $resolverId);
        if (\is_string($resolverId) && '' !== $resolverId) {
            $container->setAlias('escalated.ticket_subject_resolver', $resolverId);
        }
        $container->setParameter('escalated.kb.enabled', $config['knowledge_base']['enabled']);
        $container->setParameter('escalated.kb.public_access', $config['knowledge_base']['public_access']);
        $container->setParameter('escalated.kb.feedback_enabled', $config['knowledge_base']['feedback_enabled']);
        $container->setParameter('escalated.plugins.sdk.enabled', (bool) ($config['plugins']['sdk']['enabled'] ?? false));
        $container->setParameter('escalated.plugins.sdk.runtime_command', $config['plugins']['sdk']['runtime_command'] ?? 'node node_modules/@escalated-dev/plugin-runtime/build/bin/escalated-plugins.js');
        $container->setParameter('escalated.plugins.sdk.runtime_cwd', $config['plugins']['sdk']['runtime_cwd'] ?? '%kernel.project_dir%');
        $container->registerForAutoconfiguration(\Escalated\Symfony\Plugin\PluginInterface::class)->addTag('escalated.plugin');

        $container->setParameter('escalated.mail_domain', $config['mail_domain'] ?? 'escalated.localhost');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $doctrine = [
            'dbal' => [
                'types' => [
                    UserIdType::NAME => UserIdType::class,
                ],
            ],
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
        ];
        $manager = null;
        foreach ($container->getExtensionConfig('escalated') as $raw) {
            $manager = $raw['entity_manager'] ?? $manager;
        }
        if (null !== $manager && '' !== $manager) {
            $doctrine['orm'] = ['entity_managers' => [$manager => $doctrine['orm']]];
        }
        $container->prependExtensionConfig('doctrine', $doctrine);
    }
}
