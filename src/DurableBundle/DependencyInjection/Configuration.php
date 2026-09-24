<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function __construct(
        private readonly bool $debug = true,
    ) {}

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('durable');

        $treeBuilder->getRootNode()
            ->validate()->always(self::resolveBackend(...))->end()
            ->children()
            ->enumNode('backend')
            ->values(['in_memory', 'dbal', 'temporal'])
            ->defaultNull()
            ->info('Where the journal lives. in_memory: one process. dbal: a SQL database (DUR030). temporal: the cluster at temporal.dsn. dbal with a temporal.dsn keeps the journal in SQL and uses the cluster to serve Nexus. Derived from the deprecated event_store.type and temporal.journal when unset.')
            ->end()
            ->arrayNode('dbal')
            ->addDefaultsIfNotSet()
            ->info('DBAL backend: durable execution on a single SQL database, with no orchestration cluster (DUR030).')
            ->children()
            ->scalarNode('connection')->defaultValue('doctrine.dbal.default_connection')->info('Service id of the Doctrine\\DBAL\\Connection to use')->end()
            ->booleanNode('auto_setup')->defaultTrue()->info('Create the missing tables on the first write, never inside an open transaction. Set it to false as soon as doctrine/migrations holds the schema: otherwise the two mechanisms write one behind the other. bin/console durable:setup creates them either way.')->end()
            ->scalarNode('lock_factory')->defaultValue('lock.factory')->info('Service id of the Symfony\\Component\\Lock\\LockFactory that serialises the resumes of one execution')->end()
            ->floatNode('lock_ttl')->defaultValue(300.0)->min(1.0)->info('Seconds a resume lock outlives a worker that died holding it. The pass refreshes it at every message it sends through the bus, so it must exceed the longest step of a pass, or a second worker replays the same execution in parallel.')->end()
            ->end()
            ->end()
            ->arrayNode('event_store')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'dbal'])->defaultNull()->setDeprecated('gplanchat/durable-bundle', '0.1.0-beta1', 'The "%path%.%node%" option is deprecated: set durable.backend instead.')->end()
            ->scalarNode('table_name')->defaultValue('durable_events')->info('Table of the dbal journal.')->end()
            ->end()
            ->end()
            ->arrayNode('temporal')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('dsn')
            ->defaultNull()
            ->validate()
            ->ifTrue(static fn(mixed $dsn): bool => null !== $dsn && (!\is_string($dsn) || '' === trim($dsn)))
            ->thenInvalid('A temporal://… DSN string is expected, got %s.')
            ->end()
            ->info('A temporal://… DSN (for instance %env(DURABLE_DSN)%). When set, it turns on the native Temporal backend (gRPC); over ext-grpc when it is loaded, over curl (ext-curl) otherwise; temporal+http:// for the JSON gateway. No SQL/PDO.')
            ->end()
            ->scalarNode('guzzle_client')
            ->defaultNull()
            ->info('A service id: the application\'s GuzzleHttp\\ClientInterface, which transport=guzzle then uses — its proxy, TLS options and middleware apply to gRPC. Unused by any other transport; null builds a default client.')
            ->end()
            ->scalarNode('psr18_client')
            ->defaultNull()
            ->info('A service id: the application\'s PSR-18 client, which transport=http (the JSON gateway) then uses instead of curl. Unused by any other transport.')
            ->end()
            ->scalarNode('psr17_factory')
            ->defaultNull()
            ->info('A service id implementing both PSR-17 RequestFactoryInterface and StreamFactoryInterface (Guzzle\'s HttpFactory, nyholm\'s Psr17Factory). Defaults to psr18_client, which Symfony\'s Psr18Client satisfies on its own.')
            ->end()
            ->booleanNode('journal')
            ->defaultNull()
            ->setDeprecated('gplanchat/durable-bundle', '0.1.0-beta1', 'The "%path%.%node%" option is deprecated: set durable.backend instead.')
            ->info('false: the cluster is reachable, but the journal stays the one in event_store. An application serving a Nexus operation from a DBAL journal needs both — and there are not two sources of truth, since event_store says which one it is.')
            ->end()
            ->end()
            ->end()
            ->arrayNode('activity_transport')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'messenger'])->defaultValue('in_memory')->info('in_memory runs activities inside the workflow task; messenger routes them to transport_name.')->end()
            ->scalarNode('table_name')
            ->defaultValue('durable_activity_outbox')
            ->setDeprecated('gplanchat/durable-bundle', '0.1.0-beta1', 'The "%path%.%node%" option is read nowhere: no outbox table exists. Remove it.')
            ->end()
            ->scalarNode('transport_name')->defaultValue('durable_activities')->info('The Messenger transport activities go to when type is messenger.')->end()
            ->end()
            ->end()
            ->arrayNode('messenger')
            ->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('buses')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->info('Ids of the Messenger buses the bundle installs its middleware on (resume lock, profiler). Empty, which is the default, installs them on every bus, and that is the historical behaviour. Naming buses avoids imposing a per-execution lock on the business command bus, which carries no durable message.')
            ->end()
            ->end()
            ->end()
            ->arrayNode('profiler')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')->defaultValue($this->debug)->info('Registers the execution trace, the web profiler panel and the observer on the hot path. Defaults to kernel.debug.')->end()
            ->end()
            ->end()
            ->integerNode('max_activity_retries')->defaultValue(0)->min(0)->info('Retry ceiling for activities that set none. 0: no ceiling.')->end()
            ->arrayNode('activity_contracts')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('cache')->defaultNull()->info('PSR-6 cache pool ID for activity contract metadata')->end()
            ->arrayNode('contracts')
            ->defaultValue([])
            ->info('Class names of activity contracts to warm at cache warmup')
            ->scalarPrototype()
            ->validate()
            ->ifTrue(static fn(mixed $contract): bool => !\is_string($contract) || !interface_exists($contract))
            ->thenInvalid('%s is not an interface the autoloader can find.')
            ->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('child_workflow')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('async_messenger')->defaultFalse()->info('true: child workflows are dispatched through Messenger; false: they run inside the parent task.')->end()
            ->arrayNode('parent_link_store')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'dbal'])->defaultNull()->setDeprecated('gplanchat/durable-bundle', '0.1.0-beta1', 'The "%path%.%node%" option is deprecated: set durable.backend instead.')->end()
            ->scalarNode('table_name')->defaultValue('durable_child_workflow_parent_link')->info('Table of the dbal parent links.')->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->arrayNode('workflow_metadata')
            ->addDefaultsIfNotSet()
            ->children()
            ->enumNode('type')->values(['in_memory', 'dbal'])->defaultNull()->setDeprecated('gplanchat/durable-bundle', '0.1.0-beta1', 'The "%path%.%node%" option is deprecated: set durable.backend instead.')->end()
            ->scalarNode('table_name')->defaultValue('durable_workflow_metadata')->info('Table of the dbal workflow metadata.')->end()
            ->end()
            ->end()
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * Derives the backend from the deprecated keys, refuses a contradiction, then writes the
     * deprecated keys back from the backend: the extension reads only those.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function resolveBackend(array $config): array
    {
        $dsn = $config['temporal']['dsn'];
        $journal = $config['temporal']['journal'];
        $eventStore = $config['event_store']['type'];
        $backend = $config['backend'];
        $explicit = null !== $backend;

        if (null === $backend) {
            if (null !== $dsn && false !== $journal && 'dbal' === $eventStore) {
                throw new \InvalidArgumentException('event_store.type "dbal" and temporal.dsn are mutually exclusive — the journal cannot have two sources of truth. Set backend: dbal to keep the journal in SQL and use the cluster to serve Nexus (with the deprecated keys: temporal.journal: false).');
            }
            $backend = match (true) {
                null !== $dsn && false !== $journal => 'temporal',
                'dbal' === $eventStore => 'dbal',
                default => 'in_memory',
            };
        } elseif ('temporal' === $backend && null === $dsn) {
            throw new \InvalidArgumentException('backend "temporal" needs temporal.dsn.');
        }

        $stores = 'dbal' === $backend ? 'dbal' : 'in_memory';
        $types = [
            'event_store.type' => $eventStore,
            'workflow_metadata.type' => $config['workflow_metadata']['type'],
            'child_workflow.parent_link_store.type' => $config['child_workflow']['parent_link_store']['type'],
        ];
        foreach ($types as $path => $type) {
            if ($explicit && null !== $type && $stores !== $type) {
                throw new \InvalidArgumentException(\sprintf('%s "%s" contradicts backend "%s".', $path, $type, $backend));
            }
        }
        if ($explicit && null !== $journal && $journal !== ('temporal' === $backend)) {
            throw new \InvalidArgumentException(\sprintf('temporal.journal: %s contradicts backend "%s".', $journal ? 'true' : 'false', $backend));
        }

        $config['backend'] = $backend;
        $config['temporal']['journal'] = 'temporal' === $backend;
        $config['event_store']['type'] ??= $stores;
        // Derived from the deprecated keys, each store keeps its own type, as it did before the
        // backend node: a SQL journal alone never dragged the two other stores into SQL.
        $others = $explicit ? $stores : 'in_memory';
        $config['workflow_metadata']['type'] ??= $others;
        $config['child_workflow']['parent_link_store']['type'] ??= $others;

        return $config;
    }
}
