<?php

declare(strict_types=1);

namespace Escalated\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('escalated');
        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('user_class')
                    ->defaultValue('App\\Entity\\User')
                    ->info('The fully qualified class name of the user entity.')
                ->end()
                ->scalarNode('route_prefix')
                    ->defaultValue('/support')
                    ->info('URL prefix for all Escalated routes.')
                ->end()
                ->booleanNode('ui_enabled')
                    ->defaultTrue()
                    ->info('Enable/disable the built-in Inertia UI. When disabled, only API routes and services are available.')
                ->end()
                ->scalarNode('table_prefix')
                    ->defaultValue('escalated_')
                    ->info('Prefix for all Escalated database tables.')
                ->end()
                ->arrayNode('tickets')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('allow_customer_close')
                            ->defaultTrue()
                            ->info('Whether customers can close their own tickets.')
                        ->end()
                        ->scalarNode('default_priority')
                            ->defaultValue('medium')
                            ->info('Default priority for new tickets.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('sla')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('business_hours_only')
                            ->defaultFalse()
                        ->end()
                        ->arrayNode('business_hours')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('start')->defaultValue('09:00')->end()
                                ->scalarNode('end')->defaultValue('17:00')->end()
                                ->scalarNode('timezone')->defaultValue('UTC')->end()
                                ->arrayNode('days')
                                    ->defaultValue([1, 2, 3, 4, 5])
                                    ->integerPrototype()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
