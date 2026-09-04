<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection;

use Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceActivityRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\Port\TemporalWorkflowResumeDispatcher;
use Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityHeartbeatSender;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\Bundle\CacheWarmer\ActivityContractCacheWarmer;
use Gplanchat\Durable\Bundle\Command\DiagnoseExecutionCommand;
use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterDurableMiddlewarePass;
use Gplanchat\Durable\Bundle\EventListener\ResetDurableProfilerListener;
use Gplanchat\Durable\Bundle\Handler\ActivityRunHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler;
use Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler;
use Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher;
use Gplanchat\Durable\Bundle\Messenger\WorkflowRunDispatchProfilerMiddleware;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport;
use Gplanchat\Durable\Bundle\Transport\MessengerWorkflowTimerDispatcher;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Handler\FireWorkflowTimersHandler;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\ParentChildWorkflowCoordinator;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\LocalWorkflowBackend;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowBackendInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Query\WorkflowQueryRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
// Et non celle de HttpKernel, qui n'en est qu'une sous-classe mince — `@internal` depuis
// Symfony 7.1, dépréciée en 8.1 — et n'ajoute que les restes du cache de classes annotées.
// Celle-ci existe depuis 6.4 : l'échange ne coûte aucune version supportée.
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

final class DurableExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $container->setParameter('durable.max_activity_retries', $config['max_activity_retries'] ?? 0);

        $asyncChildMessenger = (bool) ($config['child_workflow']['async_messenger'] ?? false);
        $container->setParameter('durable.child_workflow_async_messenger', $asyncChildMessenger);

        $this->registerProfiler($container);
        $this->registerChildWorkflowParentLinkStore($container);
        $this->registerWorkflowDefinitionLoader($container);
        $this->registerEventStore($container, $config);
        $this->registerActivityTransport($container, $config);
        $this->registerActivityExecutor($container);
        $this->registerRuntime($container, $config);
        $this->registerWorkflowMessengerServices($container, $config);
        $this->registerParentChildCoordinator($container);
        // La passe qui installe les middlewares tourne bien après les extensions ; elle relit ce
        // choix ici plutôt que de le redécouvrir.
        $container->setParameter(
            RegisterDurableMiddlewarePass::BUSES_PARAMETER,
            $config['messenger']['buses'] ?? [],
        );

        $this->registerActivityContractResolver($container, $config);
        $this->registerEngine($container, $config);
        $this->registerActivityContractCacheWarmer($container, $config);
        $this->registerWorkflowControlHandlers($container);
        $this->registerWorkflowQueryRunner($container);
        $this->registerWorkflowBackend($container);
        $this->registerCommands($container, $config);
        $this->registerTemporalMirrorInfrastructure($container, $config);
        $this->registerDbalStores($container, $config);
        $this->registerInMemoryRunCatalog($container);
    }

    /**
     * Remplace les stores in-memory par leurs équivalents SQL lorsque `type: dbal` est demandé.
     *
     * Appelé en dernier : les définitions in-memory sont déjà posées, on les écrase plutôt que de
     * ramifier dans les trois méthodes qui les enregistrent.
     *
     * @param array<string, mixed> $config
     *
     * @see DUR030
     */
    private function registerDbalStores(ContainerBuilder $container, array $config): void
    {
        $eventStoreDbal = 'dbal' === ($config['event_store']['type'] ?? 'in_memory');
        $metadataDbal = 'dbal' === ($config['workflow_metadata']['type'] ?? 'in_memory');
        $parentLinkDbal = 'dbal' === ($config['child_workflow']['parent_link_store']['type'] ?? 'in_memory');

        if (!$eventStoreDbal && !$metadataDbal && !$parentLinkDbal) {
            return;
        }

        if ($eventStoreDbal && self::isTemporalNative($config)) {
            throw new \LogicException('durable: event_store.type "dbal" et temporal.dsn sont exclusifs — le journal ne peut pas avoir deux sources de vérité. Une application qui a besoin du cluster sans lui confier son journal — servir une opération Nexus, par exemple — pose temporal.journal: false.');
        }

        $connection = new Reference($config['dbal']['connection']);

        $container->register('durable.dbal.schema', DurableSchema::class)
            ->setArguments([
                $connection,
                $config['event_store']['table_name'],
                $config['workflow_metadata']['table_name'],
                $config['child_workflow']['parent_link_store']['table_name'],
            ])
            ->setPublic(false)
        ;
        $schema = new Reference('durable.dbal.schema');

        if ($eventStoreDbal) {
            $container->register('durable.event_store.dbal', DbalEventStore::class)
                ->setArguments([$connection, $schema, $config['event_store']['table_name']])
                ->setPublic(true)
            ;
            $container->setAlias(EventStoreInterface::class, 'durable.event_store.dbal')->setPublic(true);

            // Sans serveur pour sérialiser les tâches d'une exécution, le verrou est obligatoire.
            $container->register('durable.dbal.single_resume_lock', SingleResumeLockMiddleware::class)
                ->setArguments([new Reference($config['dbal']['lock_factory'])])
                ->addTag(RegisterDurableMiddlewarePass::TAG, ['priority' => 90])
                ->setPublic(false)
            ;
        }

        if ($metadataDbal) {
            $container->register('durable.workflow_metadata_store.inner', DbalWorkflowMetadataStore::class)
                ->setArguments([$connection, $schema, $config['workflow_metadata']['table_name']])
                ->setPublic(true)
            ;
            $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.inner')->setPublic(true);
        }

        if ($parentLinkDbal) {
            $container->register('durable.child_workflow_parent_link_store', DbalChildWorkflowParentLinkStore::class)
                ->setArguments([$connection, $schema, $config['child_workflow']['parent_link_store']['table_name']])
                ->setPublic(true)
            ;
        }

        // La projection ne vaut que si le journal est en SQL : c'est de lui que viennent les issues.
        // Un journal in-memory laisserait des lignes qui ne se terminent jamais.
        if ($eventStoreDbal) {
            $this->registerDbalRunCatalog($container, $connection, $schema);
        }
    }

    /**
     * Le catalogue DBAL, et les deux plumes qui l'alimentent.
     *
     * Les décorateurs sont posés ici plutôt que dans les blocs qui enregistrent le journal et les
     * métadonnées : le nom vient de `save()`, l'issue du journal, et les deux doivent pointer sur la
     * **même** projection. Les séparer aurait invité à en instancier deux.
     *
     * @see openspec/changes/backend-neutral-workflow-dashboard/design.md
     */
    private function registerDbalRunCatalog(ContainerBuilder $container, Reference $connection, Reference $schema): void
    {
        $container->register('durable.dbal.run_projection', DbalWorkflowRunProjection::class)
            ->setArguments([$connection, $schema])
            ->setPublic(false)
        ;
        $projection = new Reference('durable.dbal.run_projection');

        $container->register('durable.event_store.dbal.projecting', ProjectingEventStore::class)
            ->setArguments([new Reference('durable.event_store.dbal'), $projection])
            ->setPublic(true)
        ;
        $container->setAlias(EventStoreInterface::class, 'durable.event_store.dbal.projecting')->setPublic(true);

        // Le journal peut être en SQL sans que les métadonnées le soient : dans ce cas le magasin
        // en place — in-memory — devient l'intérieur du décorateur, plutôt que d'exiger une
        // configuration que rien n'oblige à donner.
        if (!$container->hasDefinition('durable.workflow_metadata_store.inner')) {
            $container->setDefinition(
                'durable.workflow_metadata_store.inner',
                $container->getDefinition(WorkflowMetadataStore::class),
            );
            $container->removeDefinition(WorkflowMetadataStore::class);
        }

        $container->register('durable.workflow_metadata_store.projecting', ProjectingWorkflowMetadataStore::class)
            ->setArguments([new Reference('durable.workflow_metadata_store.inner'), $projection])
            ->setPublic(true)
        ;
        $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.projecting')->setPublic(true);

        $container->register('durable.run_catalog.dbal', DbalWorkflowRunCatalog::class)
            ->setArguments([$connection, $schema])
            ->setPublic(true)
        ;
        $container->setAlias(WorkflowRunCatalogInterface::class, 'durable.run_catalog.dbal')->setPublic(true);
    }

    /**
     * Le catalogue du backend in-memory, en dernier recours.
     *
     * Il ne s'enregistre que si personne n'a déjà posé de catalogue : DBAL et Temporal passent
     * avant, chacun dans son bloc, et le garde est l'alias qu'ils déposent. Un backend qui sait
     * lire ses propres exécutions n'a rien à faire de celui-ci.
     *
     * Le catalogue lit le journal **non décoré** pour rendre un historique, et le décorateur
     * l'alimente en écriture. Les deux pointent donc sur `durable.event_store.inner` plutôt que
     * l'un sur l'autre — sans quoi le conteneur boucle.
     *
     * Ce que ça lève : le tableau de bord affichait « aucun backend lisible » sur in-memory, faute
     * de catalogue, alors que le plugin se dit neutre vis-à-vis du backend. Il l'est vraiment
     * maintenant, sur les trois.
     *
     * @see DUR037
     */
    private function registerInMemoryRunCatalog(ContainerBuilder $container): void
    {
        if ($container->hasAlias(WorkflowRunCatalogInterface::class)) {
            return;
        }

        $container->register('durable.run_catalog.in_memory', InMemoryWorkflowRunCatalog::class)
            ->setArguments([new Reference('durable.event_store.inner')])
            ->setPublic(true)
        ;
        $catalog = new Reference('durable.run_catalog.in_memory');
        $container->setAlias(WorkflowRunCatalogInterface::class, 'durable.run_catalog.in_memory')->setPublic(true);

        $container->register('durable.event_store.in_memory.projecting', ProjectingEventStore::class)
            ->setArguments([new Reference('durable.event_store.inner'), $catalog])
            ->setPublic(true)
        ;
        $container->setAlias(EventStoreInterface::class, 'durable.event_store.in_memory.projecting')->setPublic(true);

        // Le magasin de métadonnées est enregistré sous son interface, pas sous un identifiant :
        // il devient l'intérieur du décorateur, et l'interface pointe sur le décorateur.
        $container->setDefinition(
            'durable.workflow_metadata_store.inner',
            $container->getDefinition(WorkflowMetadataStore::class),
        );
        $container->removeDefinition(WorkflowMetadataStore::class);

        $container->register('durable.workflow_metadata_store.in_memory.projecting', ProjectingWorkflowMetadataStore::class)
            ->setArguments([new Reference('durable.workflow_metadata_store.inner'), $catalog])
            ->setPublic(true)
        ;
        $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.in_memory.projecting')->setPublic(true);
    }

    private function registerWorkflowDefinitionLoader(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(WorkflowDefinitionLoader::class)) {
            return;
        }

        $container->register(WorkflowDefinitionLoader::class, WorkflowDefinitionLoader::class)
            ->setPublic(false)
        ;
    }

    private function registerChildWorkflowParentLinkStore(ContainerBuilder $container): void
    {
        $container->register('durable.child_workflow_parent_link_store', InMemoryChildWorkflowParentLinkStore::class)
            ->setPublic(true)
        ;

        $container->setAlias(ChildWorkflowParentLinkStoreInterface::class, 'durable.child_workflow_parent_link_store')
            ->setPublic(true)
        ;
    }

    /**
     * Temporal « natif » : le cluster **est** le journal.
     *
     * Un DSN sans journal dit autre chose — le cluster est joignable pour ce qui en a besoin, et
     * servir une opération Nexus en a besoin, mais la source de vérité reste celle d'`event_store`.
     * Les deux ne peuvent pas partager le même drapeau : c'est lui qui débranche le transport
     * d'activités, le répartiteur de reprise et les alias de lecture du tableau de bord.
     *
     * @param array<string, mixed> $config
     */
    private static function isTemporalNative(array $config): bool
    {
        $dsn = $config['temporal']['dsn'] ?? null;

        return \is_string($dsn) && '' !== $dsn && false !== ($config['temporal']['journal'] ?? true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerEventStore(ContainerBuilder $container, array $config): void
    {
        $container->register('durable.event_store.inner', InMemoryEventStore::class)->setPublic(true);

        $temporalConfig = $config['temporal'] ?? [];
        $dsn = $temporalConfig['dsn'] ?? null;
        $journal = false !== ($temporalConfig['journal'] ?? true);
        if (\is_string($dsn) && '' !== $dsn) {
            $container->register('durable.temporal.connection', TemporalConnection::class)
                ->setFactory([TemporalConnection::class, 'fromDsn'])
                ->setArguments([$dsn])
            ;

            $container->register('durable.temporal.workflow_service_client', WorkflowServiceClient::class)
                ->setFactory([WorkflowServiceClientFactory::class, 'create'])
                ->setArguments([new Reference('durable.temporal.connection')])
            ;

            $container->register(WorkflowServiceActivityRpc::class)
                ->setArguments([new Reference('durable.temporal.workflow_service_client')])
            ;

            $container->register(WorkflowServiceExecutionRpc::class)
                ->setArguments([new Reference('durable.temporal.workflow_service_client')])
            ;

            $container->register(WorkflowServiceNexusRpc::class)
                ->setArguments([new Reference('durable.temporal.workflow_service_client')])
            ;

            $container->register(WorkflowClient::class)
                ->setArguments([
                    new Reference('durable.temporal.workflow_service_client'),
                    new Reference('durable.temporal.connection'),
                    new Reference(TemporalHistoryCursor::class),
                    new Reference(WorkflowServiceExecutionRpc::class),
                    new Reference(WorkflowDefinitionLoader::class),
                ])
            ;

            $container->setAlias(WorkflowClientInterface::class, WorkflowClient::class)
                ->setPublic(false)
            ;

            $container->register('durable.run_catalog.temporal', TemporalWorkflowRunCatalog::class)
                ->setArguments([
                    new Reference('durable.temporal.workflow_service_client'),
                    new Reference('durable.temporal.connection'),
                    new Reference(TemporalHistoryCursor::class),
                ])
                ->setPublic(true)
            ;
            if ($journal) {
                $container->setAlias(WorkflowRunCatalogInterface::class, 'durable.run_catalog.temporal')->setPublic(true);
            }

            $container->register(\Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor::class)
                ->setArguments([
                    new Reference('durable.temporal.workflow_service_client'),
                    new Reference('durable.temporal.connection'),
                ])
                ->setPublic(false)
            ;

            $container->register(WorkflowTaskRunner::class)
                ->setArguments([
                    new Reference(TemporalHistoryCursor::class),
                    new Reference(\Gplanchat\Durable\WorkflowRegistry::class),
                    new Reference('durable.temporal.connection'),
                    new Reference(WorkflowDefinitionLoader::class),
                ])
                ->setPublic(true)
            ;

            $container->register(WorkflowTaskProcessor::class)
                ->setArguments([
                    new Reference('durable.temporal.workflow_service_client'),
                    new Reference('durable.temporal.connection'),
                    new Reference(WorkflowTaskRunner::class),
                ])
                ->setPublic(true)
            ;

            $container->register('durable.event_store.temporal', TemporalReadThroughEventStore::class)
                ->setArguments([
                    new Reference('durable.event_store.inner'),
                    new Reference(TemporalHistoryCursor::class),
                    new Reference(WorkflowClientInterface::class),
                ])
                ->setPublic(true)
            ;

            if ($journal) {
                $container->setAlias(EventStoreInterface::class, 'durable.event_store.temporal')->setPublic(true);
            }

            // Sans journal, on sort sans alias : `registerDbalStores` ou `registerInMemoryRunCatalog`
            // poseront le leur, plus loin dans `load()`. C'est `event_store` qui dit lequel.
            return;
        }

        $container->setAlias(EventStoreInterface::class, 'durable.event_store.inner')->setPublic(true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerActivityTransport(ContainerBuilder $container, array $config): void
    {
        $transportConfig = $config['activity_transport'] ?? [];
        $type = $transportConfig['type'] ?? 'in_memory';
        $isTemporalNative = self::isTemporalNative($config);

        if ($isTemporalNative) {
            $container->register(ActivityTransportInterface::class, NoopActivityTransport::class)->setPublic(true);

            return;
        }

        if ('messenger' === $type) {
            $transportName = $transportConfig['transport_name'] ?? 'durable_activities';
            $container->register(ActivityTransportInterface::class, MessengerActivityTransport::class)
                ->setArguments([
                    new Reference('messenger.transport.' . $transportName),
                    new Reference('messenger.transport.' . $transportName),
                ])
                ->setPublic(true)
            ;

            return;
        }

        $container->register(ActivityTransportInterface::class, InMemoryActivityTransport::class)->setPublic(true);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerRuntime(ContainerBuilder $container, array $config): void
    {
        $container->register(\Gplanchat\Durable\ExecutionRuntime::class, \Gplanchat\Durable\ExecutionRuntime::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(ActivityTransportInterface::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                '%durable.max_activity_retries%',
                null,
                true,
                new Reference(WorkflowExecutionObserverInterface::class),
            ])
            ->setPublic(true)
        ;
    }

    private function registerParentChildCoordinator(ContainerBuilder $container): void
    {
        $container->register(ParentChildWorkflowCoordinatorInterface::class, ParentChildWorkflowCoordinator::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->setPublic(true)
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerActivityContractResolver(ContainerBuilder $container, array $config): void
    {
        $activityConfig = $config['activity_contracts'] ?? [];
        $cacheId = $activityConfig['cache'] ?? null;
        $cacheRef = null !== $cacheId && $container->hasDefinition($cacheId)
            ? new Reference($cacheId)
            : null;

        $container->register(ActivityContractResolver::class, ActivityContractResolver::class)
            ->setArguments([$cacheRef])
            ->setPublic(false)
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerActivityContractCacheWarmer(ContainerBuilder $container, array $config): void
    {
        $activityConfig = $config['activity_contracts'] ?? [];
        $contractClasses = $activityConfig['contracts'] ?? [];
        if ([] === $contractClasses) {
            return;
        }

        $container->register('durable.activity_contract_cache_warmer', ActivityContractCacheWarmer::class)
            ->setArguments([
                new Reference(ActivityContractResolver::class),
                $contractClasses,
            ])
            ->addTag('kernel.cache_warmer')
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerEngine(ContainerBuilder $container, array $config): void
    {
        $container->register(\Gplanchat\Durable\Uuid\NativeUuidV7Generator::class, \Gplanchat\Durable\Uuid\NativeUuidV7Generator::class)
            ->setPublic(false);
        $container->setAlias(\Gplanchat\Durable\Uuid\UuidGeneratorInterface::class, \Gplanchat\Durable\Uuid\NativeUuidV7Generator::class);

        $container->register(\Gplanchat\Durable\ExecutionEngine::class, \Gplanchat\Durable\ExecutionEngine::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(\Gplanchat\Durable\ExecutionRuntime::class),
                new Reference(\Gplanchat\Durable\ChildWorkflowRunner::class),
                new Reference(ParentChildWorkflowCoordinatorInterface::class),
                new Reference(ActivityContractResolver::class),
                new Reference(WorkflowDefinitionLoader::class),
                new Reference(WorkflowExecutionObserverInterface::class),
                new Reference(\Gplanchat\Durable\Uuid\UuidGeneratorInterface::class),
            ])
            ->setPublic(true)
        ;
    }

    private function registerWorkflowControlHandlers(ContainerBuilder $container): void
    {
        $container->register(DeliverWorkflowSignalHandler::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->addTag('messenger.message_handler')
        ;

        $container->register(DeliverWorkflowUpdateHandler::class)
            ->setArguments([
                new Reference(WorkflowResumeDispatcher::class),
            ])
            ->addTag('messenger.message_handler')
        ;

        $container->register(MessengerWorkflowTimerDispatcher::class)
            ->setArguments([new Reference('messenger.default_bus')])
        ;
        $container->setAlias(WorkflowTimerDispatcher::class, MessengerWorkflowTimerDispatcher::class);

        $container->register(FireWorkflowTimersHandler::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(\Gplanchat\Durable\ExecutionRuntime::class),
                new Reference(WorkflowResumeDispatcher::class),
                new Reference(WorkflowTimerDispatcher::class),
            ])
            ->addTag('messenger.message_handler')
        ;
    }

    private function registerWorkflowQueryRunner(ContainerBuilder $container): void
    {
        $container->register(WorkflowQueryRunner::class)
            ->setArguments([new Reference(EventStoreInterface::class)])
            ->setPublic(true)
        ;
    }

    private function registerActivityExecutor(ContainerBuilder $container): void
    {
        $container->register(\Gplanchat\Durable\ActivityExecutor::class, RegistryActivityExecutor::class)
            ->setPublic(true)
        ;
    }

    private function registerWorkflowBackend(ContainerBuilder $container): void
    {
        $container->register(WorkflowBackendInterface::class, LocalWorkflowBackend::class)
            ->setArguments([new Reference(\Gplanchat\Durable\ExecutionEngine::class)])
            ->setPublic(true)
        ;
    }

    /**
     * Registre métadonnées workflow, {@see ResumeWorkflowHandler}, {@see WorkflowResumeDispatcher}, {@see ChildWorkflowRunner}, etc.
     *
     * En mode Temporal natif (`durable.temporal.dsn` non vide), le {@see WorkflowResumeDispatcher} est
     * {@see TemporalWorkflowResumeDispatcher} : il appelle `WorkflowClient::startAsync()` (gRPC
     * `StartWorkflowExecution`) au lieu de dispatcher un message Messenger, et son `dispatchResume()`
     * est un no-op (Temporal re-programme lui-même le prochain workflow task).
     *
     * En mode in-memory, {@see MessengerWorkflowResumeDispatcher} est enregistré et
     * {@see ResumeWorkflowHandler} traite les messages.
     *
     * @param array<string, mixed> $config
     */
    private function registerWorkflowMessengerServices(ContainerBuilder $container, array $config): void
    {
        $isTemporalNative = self::isTemporalNative($config);

        $container->register(WorkflowMetadataStore::class, InMemoryWorkflowMetadataStore::class)
            ->setPublic(true)
        ;

        $container->register(\Gplanchat\Durable\WorkflowRegistry::class, \Gplanchat\Durable\WorkflowRegistry::class)
            ->setArguments([new Reference(WorkflowDefinitionLoader::class)])
            ->setPublic(true)
        ;

        $container->register(\Gplanchat\Durable\ChildWorkflowRunner::class, \Gplanchat\Durable\ChildWorkflowRunner::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(\Gplanchat\Durable\ExecutionRuntime::class),
                new Reference(\Gplanchat\Durable\WorkflowRegistry::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                '%durable.max_activity_retries%',
                '%durable.child_workflow_async_messenger%',
                new Reference(WorkflowResumeDispatcher::class),
                new Reference(ChildWorkflowParentLinkStoreInterface::class),
            ])
            ->setPublic(true)
        ;

        if ($isTemporalNative) {
            $container->register(WorkflowResumeDispatcher::class, TemporalWorkflowResumeDispatcher::class)
                ->setArguments([
                    new Reference(WorkflowClientInterface::class),
                    new Reference(WorkflowMetadataStore::class),
                    new Reference(WorkflowDefinitionLoader::class),
                    new Reference('durable.execution_trace'),
                ])
                ->setPublic(true)
            ;
        } else {
            $container->register(WorkflowResumeDispatcher::class, MessengerWorkflowResumeDispatcher::class)
                ->setArguments([
                    new Reference('messenger.default_bus'),
                    new Reference(WorkflowMetadataStore::class),
                ])
                ->setPublic(true)
            ;

            $container->register(ResumeWorkflowHandler::class)
                ->setArguments([
                    new Reference(\Gplanchat\Durable\ExecutionEngine::class),
                    new Reference(\Gplanchat\Durable\WorkflowRegistry::class),
                    new Reference(WorkflowMetadataStore::class),
                    new Reference(WorkflowResumeDispatcher::class),
                    new Reference(EventStoreInterface::class),
                    new Reference(ChildWorkflowParentLinkStoreInterface::class),
                    new Reference(WorkflowTimerDispatcher::class),
                    new Reference(WorkflowDefinitionLoader::class),
                ])
                ->addTag('messenger.message_handler')
            ;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerCommands(ContainerBuilder $container, array $config): void
    {
        $isTemporalNative = self::isTemporalNative($config);

        if ($isTemporalNative) {
            $container->register(TemporalActivityHeartbeatSender::class)
                ->setArguments([
                    new Reference(WorkflowServiceActivityRpc::class),
                    new Reference('durable.temporal.connection'),
                ])
                ->setPublic(false);
            $container->setAlias(ActivityHeartbeatSenderInterface::class, TemporalActivityHeartbeatSender::class)->setPublic(false);
        } else {
            $container->register(NullActivityHeartbeatSender::class)->setPublic(false);
            $container->setAlias(ActivityHeartbeatSenderInterface::class, NullActivityHeartbeatSender::class)->setPublic(false);
        }

        $container->register(ActivityMessageProcessor::class)
            ->setArguments([
                new Reference(EventStoreInterface::class),
                new Reference(ActivityTransportInterface::class),
                new Reference(\Gplanchat\Durable\ActivityExecutor::class),
                new Reference(WorkflowResumeDispatcher::class),
                new Reference(ActivityHeartbeatSenderInterface::class),
                '%durable.max_activity_retries%',
                new Reference(WorkflowExecutionObserverInterface::class),
            ])
            ->setPublic(true)
        ;

        $activityTransportConfig = $config['activity_transport'] ?? [];
        if ('messenger' === ($activityTransportConfig['type'] ?? '')
            && !$isTemporalNative) {
            $activityTransportName = $activityTransportConfig['transport_name'] ?? 'durable_activities';
            $container->register(ActivityRunHandler::class)
                ->setArguments([new Reference(ActivityMessageProcessor::class)])
                ->addTag('messenger.message_handler', ['from_transport' => $activityTransportName])
                ->setPublic(true)
            ;
        }

        $container->register(DiagnoseExecutionCommand::class)
            ->setArguments([
                new Reference(WorkflowMetadataStore::class),
                new Reference(EventStoreInterface::class),
                new Reference(ChildWorkflowParentLinkStoreInterface::class),
            ])
            ->addTag('console.command')
        ;
    }

    private function registerProfiler(ContainerBuilder $container): void
    {
        $container->register('durable.execution_trace', DurableExecutionTrace::class)
            ->setPublic(true)
        ;

        $container->setAlias(WorkflowExecutionObserverInterface::class, 'durable.execution_trace')
            ->setPublic(true)
        ;

        $container->register(ResetDurableProfilerListener::class)
            ->setArguments([new Reference('durable.execution_trace')])
            ->addTag('kernel.event_subscriber')
        ;

        $container->register('durable.messenger.middleware.workflow_run_dispatch_profiler', WorkflowRunDispatchProfilerMiddleware::class)
            ->setArguments([new Reference('durable.execution_trace')])
            // Au-dessus du verrou : ses mesures incluent alors l'attente que le verrou impose.
            ->addTag(RegisterDurableMiddlewarePass::TAG, ['priority' => 100])
        ;

        $container->register(DurableDataCollector::class)
            ->setArguments([
                new Reference('durable.execution_trace'),
                new Reference(WorkflowMetadataStore::class),
                new Reference(EventStoreInterface::class),
            ])
            ->setPublic(true)
            ->addTag('data_collector', [
                'template' => '@Durable/Collector/durable.html.twig',
                'id' => 'durable',
            ])
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerTemporalMirrorInfrastructure(ContainerBuilder $container, array $config): void
    {
        $dsn = $config['temporal']['dsn'] ?? null;
        if (!\is_string($dsn) || '' === $dsn) {
            return;
        }
        if (!$container->hasDefinition('durable.temporal.workflow_service_client')) {
            return;
        }

        $container->register('durable.temporal.activity_worker', TemporalActivityWorker::class)
            ->setArguments([
                new Reference(WorkflowServiceActivityRpc::class),
                new Reference('durable.temporal.connection'),
                new Reference(ActivityMessageProcessor::class),
                new Reference(EventStoreInterface::class),
                new Reference(ActivityHeartbeatSenderInterface::class),
            ])
            ->setPublic(true)
        ;

        // Le registre existe dès que Temporal est configuré, même sans gestionnaire déclaré : c'est
        // sa présence que NexusHandlerPass lit pour savoir si ce backend sait router. Sans elle, la
        // passe refuse — et c'est le refus au démarrage que §5.3 demande.
        $container->register('durable.temporal.nexus_registry', NexusOperationRegistry::class)
            ->setFactory([NexusOperationRegistry::class, 'routedBy'])
            ->setArguments(['temporal'])
            ->setPublic(false)
        ;

        $container->register('durable.temporal.nexus_worker', TemporalNexusWorker::class)
            ->setArguments([
                new Reference(WorkflowServiceNexusRpc::class),
                new Reference('durable.temporal.connection'),
                new Reference('durable.temporal.nexus_registry'),
            ])
            ->setPublic(true)
        ;
    }
}
