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
 * Quel catalogue d'exécutions le conteneur expose, selon le backend configuré.
 *
 * Le troisième cas a changé de réponse, et l'argument qu'il portait mérite d'être gardé plutôt
 * qu'effacé. Il disait : sans backend lisible, **rien** ne doit être enregistré, parce qu'un
 * catalogue qui ne sait rien lire afficherait une page vide là où l'exploitant doit lire « aucun
 * backend lisible n'est configuré » — et l'in-memory était ce cas, son journal vivant et mourant
 * avec le processus qui sert la requête.
 *
 * La moitié qui tient toujours : sous PHP-FPM, la requête qui rend la page n'a rien exécuté, donc
 * la liste sera vide. La moitié qui ne tient plus : « vide » et « illisible » ne se confondent plus,
 * parce que {@see \Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog::checkHealth()} porte la
 * raison dans son message et que le gabarit l'affiche. Et sur un worker long, le catalogue voit ce
 * que son processus a exécuté.
 *
 * Ce qui reste vrai des trois cas : le catalogue in-memory ne s'enregistre qu'**en dernier**, si
 * personne n'a rien posé avant lui.
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
     * Le risque du câblage n'est pas qu'un service manque : c'est que trois services corrects
     * pointent sur deux objets différents. Les décorateurs doivent alimenter **le** catalogue que
     * la page lira, et le catalogue doit lire le journal **non décoré** — sinon le conteneur
     * boucle.
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
            'le journal doit alimenter le catalogue que la page lit',
        );
        self::assertSame(
            'durable.run_catalog.in_memory',
            (string) $metadata->getArgument(1),
            'les métadonnées doivent nommer les exécutions dans ce même catalogue',
        );
        self::assertSame(
            'durable.event_store.inner',
            (string) $catalog->getArgument(0),
            'le catalogue lit le journal non décoré : lire le décorateur ferait boucler le conteneur',
        );
        self::assertSame(
            'durable.event_store.inner',
            (string) $journal->getArgument(0),
            'et c\'est le même journal que le décorateur enveloppe',
        );
    }

    public function testATemporalBackendKeepsItsOwnCatalogRatherThanTheInMemoryFallback(): void
    {
        $container = $this->load(['temporal' => ['dsn' => 'temporal://127.0.0.1:7233?namespace=durable-test']]);

        self::assertSame(
            TemporalWorkflowRunCatalog::class,
            $container->findDefinition(WorkflowRunCatalogInterface::class)->getClass(),
            'le repli ne doit prendre la main que si personne n\'a rien posé',
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
