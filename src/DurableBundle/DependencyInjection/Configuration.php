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
            ->info("Backend DBAL : exécution durable sur une seule base SQL, sans cluster d'orchestration (DUR030).")
            ->children()
            ->scalarNode('connection')->defaultValue('doctrine.dbal.default_connection')->info('Service ID de la Doctrine\\DBAL\\Connection à utiliser')->end()
            ->booleanNode('auto_setup')->defaultTrue()->info("Créer les tables manquantes à la première écriture. À passer à false dès que doctrine/migrations tient le schéma : les deux mécanismes écriraient sinon l'un derrière l'autre.")->end()
            ->scalarNode('lock_factory')->defaultValue('lock.factory')->info("Service ID de la Symfony\\Component\\Lock\\LockFactory qui sérialise les reprises d'une même exécution")->end()
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
            ->info('DSN temporal://… (ex. %env(DURABLE_DSN)%). Si défini : active le backend natif Temporal (gRPC) ; requiert ext-grpc. Pas de SQL/PDO.')
            ->end()
            ->booleanNode('journal')
            ->defaultTrue()
            ->info("false : le cluster est joignable, mais le journal reste celui d'event_store. Une application qui sert une opération Nexus depuis un journal DBAL a besoin des deux — et il n'y a pas deux sources de vérité, puisque event_store dit laquelle.")
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
