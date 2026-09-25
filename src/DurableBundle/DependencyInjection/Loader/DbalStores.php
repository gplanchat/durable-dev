<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Bridge\Dbal\Store\DbalChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Dbal\Store\DbalEventStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowMetadataStore;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use Gplanchat\Durable\Bundle\Command\SetupCommand;
use Gplanchat\Durable\Bundle\DependencyInjection\Compiler\RegisterDurableMiddlewarePass;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Bundle\SchemaListener\DurableSchemaListener;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The SQL side of the dbal backend: the journal, metadata and run catalog stores, and their schema.
 *
 * Moved verbatim out of {@see DurableExtension} (#342), which calls these in its load() order.
 *
 * @internal
 */
final class DbalStores
{
    /**
     * The DBAL catalog, and the two pens that feed it.
     *
     * The decorators are placed here rather than in the blocks that register the journal and the
     * metadata: the name comes from `save()`, the outcome from the journal, and the two must point
     * at the **same** projection. Separating them would have invited instantiating two of them.
     *
     * @see openspec/changes/backend-neutral-workflow-dashboard/design.md
     */
    public static function registerDbalRunCatalog(ContainerBuilder $container, Reference $connection, Reference $schema): void
    {
        $container->register('durable.dbal.run_projection', DbalWorkflowRunProjection::class)
            ->setArguments([$connection, $schema])
            ->setPublic(false)
        ;
        $projection = new Reference('durable.dbal.run_projection');
        // The resume handler records the pickup where the worker takes the message (#447).
        $container->setAlias(WorkflowRunPickupProjectionInterface::class, 'durable.dbal.run_projection')->setPublic(false);

        $container->register('durable.event_store.dbal.projecting', ProjectingEventStore::class)
            ->setArguments([new Reference('durable.event_store.dbal'), $projection])
            ->setPublic(false)
        ;
        $container->setAlias(EventStoreInterface::class, 'durable.event_store.dbal.projecting')->setPublic(true);

        // The journal can be in SQL without the metadata being so: in that case the store already
        // in place — in-memory — becomes the inside of the decorator, rather than demanding a
        // configuration nothing forces anyone to give.
        if (!$container->hasDefinition('durable.workflow_metadata_store.inner')) {
            $container->setDefinition(
                'durable.workflow_metadata_store.inner',
                $container->getDefinition(WorkflowMetadataStore::class)->setPublic(false),
            );
            $container->removeDefinition(WorkflowMetadataStore::class);
        }

        $container->register('durable.workflow_metadata_store.projecting', ProjectingWorkflowMetadataStore::class)
            ->setArguments([new Reference('durable.workflow_metadata_store.inner'), $projection])
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.projecting')->setPublic(true);

        $container->register('durable.run_catalog.dbal', DbalWorkflowRunCatalog::class)
            ->setArguments([$connection, $schema])
            ->setPublic(false)
        ;
        $container->setAlias(WorkflowRunCatalogInterface::class, 'durable.run_catalog.dbal')->setPublic(true);
    }

    /**
     * Replaces the in-memory stores with their SQL equivalents when `type: dbal` is asked for.
     *
     * Called last: the in-memory definitions are already in place, we overwrite them rather than
     * branch inside the three methods that register them.
     *
     * @param array<string, mixed> $config
     *
     * @see DUR030
     */
    public static function registerDbalStores(ContainerBuilder $container, array $config): void
    {
        $eventStoreDbal = 'dbal' === ($config['event_store']['type'] ?? 'in_memory');
        $metadataDbal = 'dbal' === ($config['workflow_metadata']['type'] ?? 'in_memory');
        $parentLinkDbal = 'dbal' === ($config['child_workflow']['parent_link_store']['type'] ?? 'in_memory');

        if (!$eventStoreDbal && !$metadataDbal && !$parentLinkDbal) {
            return;
        }

        $connection = new Reference($config['dbal']['connection']);

        $container->register('durable.dbal.schema', DurableSchema::class)
            ->setArguments([
                $connection,
                $config['event_store']['table_name'],
                $config['workflow_metadata']['table_name'],
                $config['child_workflow']['parent_link_store']['table_name'],
            ])
            ->setArgument('$autoSetup', $config['dbal']['auto_setup'])
            ->setPublic(false)
        ;
        $schema = new Reference('durable.dbal.schema');

        $container->register(SetupCommand::class)
            ->setArguments([$schema])
            ->addTag('console.command')
        ;

        // Without this listener, `doctrine:migrations:diff` does not see the journal's tables and
        // generates their removal. Registered only when the ORM is there: the DBAL bridge works
        // without it, and an application that has only the DBAL has no schema to complete.
        if (class_exists(GenerateSchemaEventArgs::class)) {
            $container->register('durable.dbal.schema_listener', DurableSchemaListener::class)
                ->setArguments([$schema])
                ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema'])
                ->setPublic(false)
            ;
        }

        if ($eventStoreDbal) {
            $container->register('durable.event_store.dbal', DbalEventStore::class)
                ->setArguments([$connection, $schema, $config['event_store']['table_name']])
                ->setPublic(false)
            ;
            $container->setAlias(EventStoreInterface::class, 'durable.event_store.dbal')->setPublic(true);

            // With no server to serialize the tasks of one execution, the lock is mandatory.
            $container->register('durable.dbal.single_resume_lock', SingleResumeLockMiddleware::class)
                ->setArguments([new Reference($config['dbal']['lock_factory']), $config['dbal']['lock_ttl']])
                ->addTag(RegisterDurableMiddlewarePass::TAG, ['priority' => 90])
                ->setPublic(false)
            ;
        }

        if ($metadataDbal) {
            $container->register('durable.workflow_metadata_store.inner', DbalWorkflowMetadataStore::class)
                ->setArguments([$connection, $schema, $config['workflow_metadata']['table_name']])
                ->setPublic(false)
            ;
            $container->setAlias(WorkflowMetadataStore::class, 'durable.workflow_metadata_store.inner')->setPublic(true);
        }

        if ($parentLinkDbal) {
            $container->register('durable.child_workflow_parent_link_store', DbalChildWorkflowParentLinkStore::class)
                ->setArguments([$connection, $schema, $config['child_workflow']['parent_link_store']['table_name']])
                ->setPublic(true)
            ;
        }

        // The projection is only worth it if the journal is in SQL: that is where the outcomes come
        // from. An in-memory journal would leave rows that never finish.
        if ($eventStoreDbal) {
            self::registerDbalRunCatalog($container, $connection, $schema);
        }
    }
}
