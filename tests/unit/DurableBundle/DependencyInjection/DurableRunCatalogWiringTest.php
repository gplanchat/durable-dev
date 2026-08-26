<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle\DependencyInjection;

use Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog;
use Gplanchat\Bridge\Dbal\Store\ProjectingEventStore;
use Gplanchat\Bridge\Dbal\Store\ProjectingWorkflowMetadataStore;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Durable\Bundle\DependencyInjection\DurableExtension;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Quel catalogue d'exécutions le conteneur expose, selon le backend configuré.
 *
 * Le troisième cas est le plus important des trois : sans backend lisible, **rien** ne doit être
 * enregistré. Câbler un catalogue qui ne sait rien lire ferait afficher une page vide là où
 * l'exploitant doit lire « aucun backend lisible n'est configuré » — et l'in-memory est précisément
 * ce cas, son journal vivant et mourant avec le processus qui sert la requête.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/tasks.md §6.1
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
            'sans décoration du journal, aucune issue ne serait jamais projetée',
        );
        self::assertSame(
            ProjectingWorkflowMetadataStore::class,
            $container->findDefinition(WorkflowMetadataStore::class)->getClass(),
            'sans décoration des métadonnées, aucune exécution ne serait jamais nommée',
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

    public function testNoReadableBackendExposesNoCatalogAtAll(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->hasAlias(WorkflowRunCatalogInterface::class));
        self::assertFalse($container->hasDefinition(WorkflowRunCatalogInterface::class));
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
