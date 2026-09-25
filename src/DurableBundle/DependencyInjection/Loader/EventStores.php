<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\DependencyInjection\Loader;

use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Where the journal lives: the event store for the configured backend, and the child-to-parent link store.
 *
 * Moved verbatim out of {@see DurableExtension} (#342), which calls these in its load() order.
 *
 * @internal
 */
final class EventStores
{
    public static function registerChildWorkflowParentLinkStore(ContainerBuilder $container): void
    {
        $container->register('durable.child_workflow_parent_link_store', InMemoryChildWorkflowParentLinkStore::class)
            ->setPublic(true)
        ;

        $container->setAlias(ChildWorkflowParentLinkStoreInterface::class, 'durable.child_workflow_parent_link_store')
            ->setPublic(true)
        ;
    }
}
