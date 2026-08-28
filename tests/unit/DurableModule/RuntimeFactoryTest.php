<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Magento;

use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalJournalEventStore;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Durable\Magento\Runtime\RuntimeFactory;
use Gplanchat\Durable\Magento\Workflow\Activity\DemoOrderActivities;
use Gplanchat\Durable\Magento\Workflow\PlaceOrderWorkflow;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use PHPUnit\Framework\TestCase;

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
        $runtime = (new RuntimeFactory(activityHandlers: [new DemoOrderActivities()]))->create();

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
            workflowClasses: [PlaceOrderWorkflow::class],
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
     * La déclaration de la 3.1 ne doit rien savoir du backend : c'est le même `di.xml` des deux
     * côtés, et un workflow déclaré une fois tourne sur l'un comme sur l'autre.
     */
    public function testDeclarationIsOrthogonalToWhereTheJournalLives(): void
    {
        $declared = static fn(?string $dsn): array => (new RuntimeFactory(
            workflowClasses: [PlaceOrderWorkflow::class],
            activityHandlers: [new DemoOrderActivities()],
            temporalDsn: $dsn,
        ))->create()->declaredActivities();

        self::assertSame(
            ['durable.demo.charge', 'durable.demo.reserve', 'durable.demo.notify'],
            $declared(null),
        );
        self::assertSame($declared(null), $declared('temporal://127.0.0.1:7234?namespace=default&tls=0'));
    }
}
