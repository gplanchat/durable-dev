<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunProjection;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
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
}
