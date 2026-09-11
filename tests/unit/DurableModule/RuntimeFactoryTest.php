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
 * Where the journal lives, and who decides that.
 *
 * Magento reaches only two backends, and that is no timidity: it ships neither of the two
 * connection types the SQL bridges bind to. The choice between the two therefore does not come
 * down to a backend name copied into a configuration — 2.3 removed that surface — but to the
 * **presence of a DSN**. No DSN, no cluster: the journal lives in the process and dies with it.
 * A DSN, and it lives in the cluster.
 *
 * It is the same rule as for the SQL bridges, one notch lower: what is installed and configured
 * decides, not a string that can be written crooked.
 */
/*
 * The DSNs below name transport=grpc-curl: which client the factory builds is not what these
 * tests measure, and the ext-grpc client cannot even be instantiated without the extension, so
 * the curl one keeps the tests runnable in the CI job that deliberately has no ext-grpc.
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
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0&transport=grpc-curl',
        ))->create();

        self::assertInstanceOf(TemporalJournalEventStore::class, $runtime->eventStore());
    }

    /**
     * What the administration screen queries.
     *
     * The catalog is **not** derivable from the event store: `InMemoryWorkflowRunCatalog` keeps
     * its own list, fed by `recordStart()`/`recordOutcome()` in the process that executes. An
     * administration request executes nothing, so it has nothing to read there. Listing the
     * executions of a cluster means asking the cluster — and the bridge already ships the class
     * that knows how to do it.
     */
    public function testTheCatalogAsksTheClusterWhenThereIsOne(): void
    {
        $catalog = (new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0&transport=grpc-curl',
        ))->catalog();

        self::assertInstanceOf(TemporalWorkflowRunCatalog::class, $catalog);
    }

    public function testWithoutAClusterTheCatalogIsTheProcessItself(): void
    {
        $catalog = (new RuntimeFactory())->catalog();

        self::assertInstanceOf(InMemoryWorkflowRunCatalog::class, $catalog);
    }

    /**
     * What was missing for the journals to close.
     *
     * With no worker, an execution appended to the cluster stays `running` there forever: nobody
     * answers the tasks in its queue. The bridge ships the four objects; the module has only to
     * assemble them and to loop.
     */
    public function testAJournalWorkerIsAssembledWhenThereIsACluster(): void
    {
        $worker = (new RuntimeFactory(
            workflowClasses: [OrderWorkflow::class],
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0&transport=grpc-curl',
        ))->journalWorker();

        self::assertInstanceOf(WorkflowTaskProcessor::class, $worker);
    }

    /**
     * A journal worker with no cluster would not be useless, it would be deceptive: it would
     * run, would never find anything, and the operator would believe they had a worker.
     */
    public function testAskingForAJournalWorkerWithoutAClusterFailsSayingSo(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/durable\/temporal\/dsn/');

        (new RuntimeFactory())->journalWorker();
    }

    /**
     * What was missing for the order to start moving again.
     *
     * §5.3 had measured the half that counts — the card is not charged a second time — and the
     * half that was missing: the execution stayed suspended because its activity had been
     * dispatched into the in-memory transport of a dead process. On Temporal, an activity is a
     * task somebody has to pop off, and that somebody is this worker.
     */
    public function testAnActivityWorkerIsAssembledWhenThereIsACluster(): void
    {
        $worker = (new RuntimeFactory(
            activityHandlers: [new RecordingOrderActivities()],
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0&transport=grpc-curl',
        ))->activityWorker();

        self::assertInstanceOf(TemporalActivityWorker::class, $worker);
    }

    /**
     * And what it takes to start an execution **on the cluster** rather than in this process
     * here: `MagentoRuntime::run()` executes here, so its activities never leave memory.
     */
    public function testAWorkflowCanBeStartedOnTheClusterRatherThanInThisProcess(): void
    {
        $client = (new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0&transport=grpc-curl',
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
     * The declaration of 3.1 must know nothing of the backend: it is the same `di.xml` on both
     * sides, and a workflow declared once runs on the one as on the other.
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
        self::assertSame($declared(null), $declared('temporal://127.0.0.1:7234?namespace=default&tls=0&transport=grpc-curl'));
    }
}
