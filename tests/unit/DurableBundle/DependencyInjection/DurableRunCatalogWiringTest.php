<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Which execution catalog the container exposes, depending on the configured backend.
 *
 * The third case changed its answer, and the argument it carried deserves to be kept rather than
 * erased. It said: without a readable backend, **nothing** must be registered, because a catalog
 * that can read nothing would display an empty page where the operator must read "no readable
 * backend is configured" — and in-memory was that case, its journal living and dying with the
 * process that serves the request.
 *
 * The half that still holds: under PHP-FPM, the request that renders the page has executed
 * nothing, so the list will be empty. The half that no longer holds: "empty" and "unreadable" are
 * no longer confused with one another,
 * because {@see \Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog::checkHealth()} carries the
 * reason in its message and the template displays it. And on a long-lived worker, the catalog sees
 * what its process has executed.
 *
 * What stays true of all three cases: the in-memory catalog registers itself only **last**, if
 * nobody has laid anything down before it.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/tasks.md §6.1
 * @see DUR037
 */
final class DurableRunCatalogWiringTest extends TestCase
{
    public function testTheDbalBackendExposesItsCatalog(): void
    {
        $container = $this->load([
            'event_store' => ['type' => 'dbal'],
            'workflow_metadata' => ['type' => 'dbal'],
        ]);

        self::assertTrue($container->hasAlias(WorkflowRunCatalogInterface::class));
        self::assertSame(
            DbalWorkflowRunCatalog::class,
            $container->findDefinition(WorkflowRunCatalogInterface::class)->getClass(),
        );
    }

    public function testTheDbalBackendProjectsWhatItWillLaterRead(): void
    {
        $container = $this->load([
            'event_store' => ['type' => 'dbal'],
            'workflow_metadata' => ['type' => 'dbal'],
        ]);

        self::assertSame(
            ProjectingEventStore::class,
            $container->findDefinition(EventStoreInterface::class)->getClass(),
            'without decorating the journal, no outcome would ever be projected',
        );
        self::assertSame(
            ProjectingWorkflowMetadataStore::class,
            $container->findDefinition(WorkflowMetadataStore::class)->getClass(),
            'without decorating the metadata, no execution would ever be named',
        );
    }

    public function testTheTemporalBackendExposesItsCatalog(): void
    {
        $container = $this->load(['temporal' => ['dsn' => 'temporal://127.0.0.1:7233?namespace=durable-test']]);

        self::assertTrue($container->hasAlias(WorkflowRunCatalogInterface::class));
        self::assertSame(
            TemporalWorkflowRunCatalog::class,
            $container->findDefinition(WorkflowRunCatalogInterface::class)->getClass(),
        );
    }

    public function testTheInMemoryBackendExposesItsCatalogToo(): void
    {
        $container = $this->load([]);

        self::assertTrue($container->hasAlias(WorkflowRunCatalogInterface::class));
        self::assertSame(
            InMemoryWorkflowRunCatalog::class,
            $container->findDefinition(WorkflowRunCatalogInterface::class)->getClass(),
        );
        self::assertSame(
            ProjectingEventStore::class,
            $container->findDefinition(EventStoreInterface::class)->getClass(),
        );
        self::assertSame(
            ProjectingWorkflowMetadataStore::class,
            $container->findDefinition(WorkflowMetadataStore::class)->getClass(),
        );
    }

    /**
     * The risk in the wiring is not that a service is missing: it is that three correct services
     * point at two different objects. The decorators must feed **the** catalog the page will
     * read, and the catalog must read the **undecorated** journal — otherwise the container
     * loops.
     */
    public function testTheThreeInMemoryServicesShareOneCatalogAndOneJournal(): void
    {
        $container = $this->load([]);

        $catalog = $container->findDefinition(WorkflowRunCatalogInterface::class);
        $journal = $container->findDefinition(EventStoreInterface::class);
        $metadata = $container->findDefinition(WorkflowMetadataStore::class);

        self::assertSame(
            'durable.run_catalog.in_memory',
            (string) $journal->getArgument(1),
            'the journal must feed the catalog the page reads',
        );
        self::assertSame(
            'durable.run_catalog.in_memory',
            (string) $metadata->getArgument(1),
            'the metadata must name the executions in that same catalog',
        );
        self::assertSame(
            'durable.event_store.inner',
            (string) $catalog->getArgument(0),
            'the catalog reads the undecorated journal: reading the decorator would make the container loop',
        );
        self::assertSame(
            'durable.event_store.inner',
            (string) $journal->getArgument(0),
            'and it is the same journal that the decorator wraps',
        );
    }

    public function testATemporalBackendKeepsItsOwnCatalogRatherThanTheInMemoryFallback(): void
    {
        $container = $this->load(['temporal' => ['dsn' => 'temporal://127.0.0.1:7233?namespace=durable-test']]);

        self::assertSame(
            TemporalWorkflowRunCatalog::class,
            $container->findDefinition(WorkflowRunCatalogInterface::class)->getClass(),
            'the fallback must take over only if nobody has laid anything down',
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new DurableExtension())->load([$config], $container);

        return $container;
    }
}
