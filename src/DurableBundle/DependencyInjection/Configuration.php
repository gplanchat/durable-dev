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
            ->arrayNode('dbal')
            ->addDefaultsIfNotSet()
            ->info('DBAL backend: durable execution on a single SQL database, with no orchestration cluster (DUR030).')
            ->children()
            ->scalarNode('connection')->defaultValue('doctrine.dbal.default_connection')->info('Service id of the Doctrine\\DBAL\\Connection to use')->end()
            ->scalarNode('lock_factory')->defaultValue('lock.factory')->info('Service id of the Symfony\\Component\\Lock\\LockFactory that serialises the resumes of one execution')->end()
            ->end()
            ->end()
            ->arrayNode('event_store')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'dbal'])->defaultValue('in_memory')->end()
            ->scalarNode('table_name')->defaultValue('durable_events')->end()
            ->end()
            ->end()
            ->arrayNode('temporal')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('dsn')
            ->defaultNull()
            ->info('A temporal://… DSN (for instance %env(DURABLE_DSN)%). When set, it turns on the native Temporal backend (gRPC); requires ext-grpc. No SQL/PDO.')
            ->end()
            ->booleanNode('journal')
            ->defaultTrue()
            ->info('false: the cluster is reachable, but the journal stays the one in event_store. An application serving a Nexus operation from a DBAL journal needs both — and there are not two sources of truth, since event_store says which one it is.')
            ->end()
            ->end()
            ->end()
            ->arrayNode('activity_transport')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'messenger'])->defaultValue('in_memory')->end()
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
