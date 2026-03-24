<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('durable');

        $treeBuilder->getRootNode()
            ->children()
            ->arrayNode('event_store')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'dbal'])->defaultValue('in_memory')->end()
            ->scalarNode('table_name')->defaultValue('durable_events')->end()
            ->end()
            ->end()
            ->arrayNode('activity_transport')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'dbal', 'messenger'])->defaultValue('in_memory')->end()
            ->scalarNode('table_name')->defaultValue('durable_activity_outbox')->end()
            ->scalarNode('transport_name')->defaultValue('durable_activities')->end()
            ->end()
            ->end()
            ->integerNode('max_activity_retries')->defaultValue(0)->end()
            ->arrayNode('activity_contracts')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('cache')->defaultNull()->info('PSR-6 cache pool ID for activity contract metadata')->end()
            ->arrayNode('contracts')
            ->defaultValue([])
            ->info('Class names of activity contracts to warm at cache warmup')
            ->scalarPrototype()->end()
            ->end()
            ->end()
            ->end()
            ->booleanNode('distributed')->defaultFalse()->end()
            ->scalarNode('workflow_transport')->defaultValue('durable_workflows')->end()
            ->arrayNode('child_workflow')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('async_messenger')->defaultFalse()->end()
            ->arrayNode('parent_link_store')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'dbal'])->defaultValue('in_memory')->end()
            ->scalarNode('table_name')->defaultValue('durable_child_workflow_parent_link')->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('workflow_metadata')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'dbal'])->defaultValue('in_memory')->end()
            ->scalarNode('table_name')->defaultValue('durable_workflow_metadata')->end()
            ->end()
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
