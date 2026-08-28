<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalJournalEventStore;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;
use unit\DurableModule\Fixture\OrderWorkflow;
use unit\DurableModule\Fixture\RecordingOrderActivities;

/**
 * Où vit le journal, et qui le décide.
 *
 * Magento n'atteint que deux backends, et ce n'est pas une timidité : il ne livre aucun des deux
 * types de connexion auxquels les ponts SQL se lient. Le choix entre les deux ne revient donc pas
 * à un nom de backend recopié dans une configuration — la 2.3 a retiré cette surface — mais à la
 * **présence d'un DSN**. Pas de DSN, pas de grappe : le journal vit dans le processus et meurt
 * avec lui. Un DSN, et il vit dans le cluster.
 *
 * C'est la même règle que pour les ponts SQL, un cran plus bas : ce qui est installé et configuré
 * décide, pas une chaîne qu'on peut écrire de travers.
 */
final class RuntimeFactoryTest extends TestCase
{
    public function testWithoutADsnTheJournalLivesInTheProcessAndDiesWithIt(): void
    {
        $runtime = (new RuntimeFactory(activityHandlers: [new RecordingOrderActivities()]))->create();

        self::assertInstanceOf(InMemoryEventStore::class, $runtime->eventStore());
    }

    public function testADsnPutsTheJournalInTheCluster(): void
    {
        $runtime = (new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
        ))->create();

        self::assertInstanceOf(TemporalJournalEventStore::class, $runtime->eventStore());
    }

    /**
     * Ce que l'écran d'administration interroge.
     *
     * Le catalogue n'est **pas** dérivable du magasin d'événements : `InMemoryWorkflowRunCatalog`
     * tient sa propre liste, alimentée par `recordStart()`/`recordOutcome()` dans le processus qui
     * exécute. Une requête d'administration n'exécute rien, donc elle n'a rien à y lire. Lister les
     * exécutions d'une grappe, c'est demander à la grappe — et le pont livre déjà la classe qui
     * sait le faire.
     */
    public function testTheCatalogAsksTheClusterWhenThereIsOne(): void
    {
        $catalog = (new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
        ))->catalog();

        self::assertInstanceOf(TemporalWorkflowRunCatalog::class, $catalog);
    }

    public function testWithoutAClusterTheCatalogIsTheProcessItself(): void
    {
        $catalog = (new RuntimeFactory())->catalog();

        self::assertInstanceOf(InMemoryWorkflowRunCatalog::class, $catalog);
    }

    /**
     * Ce qui manquait pour que les journaux se closent.
     *
     * Sans worker, une exécution appendue au cluster y reste `running` pour toujours : personne ne
     * répond aux tâches de sa file. Le pont livre les quatre objets ; le module n'a qu'à les
     * assembler et à boucler.
     */
    public function testAJournalWorkerIsAssembledWhenThereIsACluster(): void
    {
        $worker = (new RuntimeFactory(
            workflowClasses: [OrderWorkflow::class],
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
        ))->journalWorker();

        self::assertInstanceOf(WorkflowTaskProcessor::class, $worker);
    }

    /**
     * Un worker de journal sans grappe ne serait pas inutile, il serait trompeur : il tournerait,
     * ne trouverait jamais rien, et l'exploitant croirait avoir un worker.
     */
    public function testAskingForAJournalWorkerWithoutAClusterFailsSayingSo(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/durable\/temporal\/dsn/');

        (new RuntimeFactory())->journalWorker();
    }

    /**
     * Ce qui manquait pour que l'ordre reparte.
     *
     * La §5.3 avait mesuré la moitié qui compte — la carte n'est pas re-débitée — et la moitié qui
     * manquait : l'exécution restait suspendue parce que son activité avait été distribuée dans le
     * transport en mémoire d'un processus mort. Sur Temporal, une activité est une tâche que
     * quelqu'un doit dépiler, et ce quelqu'un est ce worker.
     */
    public function testAnActivityWorkerIsAssembledWhenThereIsACluster(): void
    {
        $worker = (new RuntimeFactory(
            activityHandlers: [new RecordingOrderActivities()],
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
        ))->activityWorker();

        self::assertInstanceOf(TemporalActivityWorker::class, $worker);
    }

    /**
     * Et de quoi démarrer une exécution **sur la grappe** plutôt que dans ce processus-ci :
     * `MagentoRuntime::run()` exécute ici, donc ses activités ne quittent jamais la mémoire.
     */
    public function testAWorkflowCanBeStartedOnTheClusterRatherThanInThisProcess(): void
    {
        $client = (new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
        ))->workflowClient();

        self::assertInstanceOf(WorkflowClient::class, $client);
    }

    public function testNeitherIsOfferedWithoutACluster(): void
    {
        foreach (['activityWorker', 'workflowClient'] as $method) {
            try {
                (new RuntimeFactory())->{$method}();
                self::fail(\sprintf('%s() should have been refused without a cluster.', $method));
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('durable/temporal/dsn', $exception->getMessage());
            }
        }
    }

    /**
     * La déclaration de la 3.1 ne doit rien savoir du backend : c'est le même `di.xml` des deux
     * côtés, et un workflow déclaré une fois tourne sur l'un comme sur l'autre.
     */
    public function testDeclarationIsOrthogonalToWhereTheJournalLives(): void
    {
        $declared = static fn(?string $dsn): array => (new RuntimeFactory(
            workflowClasses: [OrderWorkflow::class],
            activityHandlers: [new RecordingOrderActivities()],
            temporalDsn: $dsn,
        ))->create()->declaredActivities();

        self::assertSame(
            ['test.order.charge', 'test.order.reserve', 'test.order.notify'],
            $declared(null),
        );
        self::assertSame($declared(null), $declared('temporal://127.0.0.1:7234?namespace=default&tls=0'));
    }
}
