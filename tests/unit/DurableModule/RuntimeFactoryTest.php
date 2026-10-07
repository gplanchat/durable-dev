<?php

declare(strict_types=1);

namespace unit\DurableModule;

use Gplanchat\Bridge\Temporal\Codec\PayloadCodecInterface;
use Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Exception\ContinuationCapReachedException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use PHPUnit\Framework\TestCase;
use unit\Durable\Fixtures\CounterWorkflow;
use unit\Durable\Fixtures\FrozenClock;
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
final class RuntimeFactoryTest extends TestCase
{
    public function testWithoutADsnTheJournalLivesInTheProcessAndDiesWithIt(): void
    {
        $runtime = (new RuntimeFactory(activityHandlers: [new RecordingOrderActivities()]))->create();

        self::assertInstanceOf(InMemoryEventStore::class, $runtime->eventStore());
    }

    /**
     * The cluster's own history is the journal of what it runs: the store reads it through, and a
     * run executed in this process keeps its events here, as without a DSN (#356).
     */
    public function testADsnReadsTheClusterHistoryThrough(): void
    {
        $runtime = (new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
        ))->create();

        self::assertInstanceOf(TemporalReadThroughEventStore::class, $runtime->eventStore());
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
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
        ))->catalog();

        self::assertInstanceOf(TemporalWorkflowRunCatalog::class, $catalog);
    }

    public function testTheHandedGuzzleClientIsTheOneTransportGuzzleUses(): void
    {
        // A di.xml <argument xsi:type="object"> hands the application's client; every Temporal
        // call of the runtime then goes through it.
        $calls = 0;
        $guzzle = new \GuzzleHttp\Client(['handler' => static function (\Psr\Http\Message\RequestInterface $request) use (&$calls): \GuzzleHttp\Promise\PromiseInterface {
            ++$calls;

            // A trailers-only PERMISSION_DENIED: a status the retry leaves alone, so one call is one request.
            return \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, ['grpc-status' => '7']));
        }]);

        $catalog = (new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0&transport=guzzle',
            guzzle: $guzzle,
        ))->catalog();

        try {
            $catalog->listRuns();
        } catch (\RuntimeException) {
            // PERMISSION_DENIED: the stub refuses, and that is all it is for.
        }

        self::assertSame(1, $calls);
    }

    public function testAnUnavailableFrontendIsRetriedThroughTheHandedGuzzleClient(): void
    {
        // The call count above is one because PERMISSION_DENIED is final; UNAVAILABLE is not.
        $calls = 0;
        $guzzle = new \GuzzleHttp\Client(['handler' => static function (\Psr\Http\Message\RequestInterface $request) use (&$calls): \GuzzleHttp\Promise\PromiseInterface {
            return 1 === ++$calls
                ? \GuzzleHttp\Promise\Create::rejectionFor(new \GuzzleHttp\Exception\ConnectException('refused', $request, null, ['errno' => 7]))
                : \GuzzleHttp\Promise\Create::promiseFor(new \GuzzleHttp\Psr7\Response(200, ['grpc-status' => '7']));
        }]);

        $catalog = (new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0&transport=guzzle',
            guzzle: $guzzle,
        ))->catalog();

        $failure = null;

        try {
            $catalog->listRuns();
        } catch (\RuntimeException $e) {
            $failure = $e;
        }

        self::assertSame(7, $failure?->getCode(), 'the retry surfaces the last answer, not the first');
        self::assertSame(2, $calls);
    }

    public function testTheHandedPsr18ClientCarriesTheJsonGateway(): void
    {
        $sent = 0;
        $psr18 = new class ($sent) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private int &$sent) {}

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                ++$this->sent;

                return new \GuzzleHttp\Psr7\Response(503, [], '{"code":14,"message":"stub"}');
            }
        };
        $factory = new \GuzzleHttp\Psr7\HttpFactory();

        $catalog = (new RuntimeFactory(
            temporalDsn: 'temporal+http://127.0.0.1:7243?namespace=default',
            jsonGateway: new \Gplanchat\Bridge\Temporal\Http\Psr18Http($psr18, $factory, $factory),
        ))->catalog();

        try {
            $catalog->listRuns();
        } catch (\RuntimeException) {
            // UNAVAILABLE: the stub answers so, and that is all it is for.
        }

        self::assertSame(1, $sent);
    }

    /** DUR055: the shop's codec, handed in `di.xml`, encodes what the client sends. */
    public function testTheHandedCodecEncodesWhatTheClientSends(): void
    {
        $sent = 0;
        $psr18 = new class ($sent) implements \Psr\Http\Client\ClientInterface {
            public function __construct(private int &$sent) {}

            public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                ++$this->sent;

                return new \GuzzleHttp\Psr7\Response(503, [], '{"code":14,"message":"stub"}');
            }
        };
        $factory = new \GuzzleHttp\Psr7\HttpFactory();
        $codec = $this->createMock(PayloadCodecInterface::class);
        $codec->expects(self::atLeastOnce())->method('encode')->willReturnArgument(0);

        $client = (new RuntimeFactory(
            temporalDsn: 'temporal+http://127.0.0.1:7243?namespace=default',
            jsonGateway: new \Gplanchat\Bridge\Temporal\Http\Psr18Http($psr18, $factory, $factory),
            codec: $codec,
        ))->workflowClient();

        try {
            $client->signal('order-1', 'go', ['email' => 'secret']);
        } catch (\RuntimeException) {
            // UNAVAILABLE: the stub answers so; the payload was encoded before it left.
        }

        self::assertGreaterThanOrEqual(1, $sent, 'the request went through the handed gateway');
    }

    /**
     * Magento has no PSR-20 clock: the core's system clock by default, the one `di.xml` hands
     * otherwise, for every in-process service that reads time (#617).
     */
    public function testTheHandedClockStampsTheProcessJournal(): void
    {
        $runtime = (new RuntimeFactory(clock: new FrozenClock(1_700_000_000.0)))->create();
        $runtime->eventStore()->append(new WorkflowSignalReceived(ExecutionId::fromString('exec-1'), 'go', []));

        $stamps = [];
        foreach ($runtime->eventStore()->readStreamWithRecordedAt(ExecutionId::fromString('exec-1')) as $row) {
            $stamps[] = $row['recordedAt']->format('U');
        }
        self::assertSame(['1700000000'], $stamps);
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
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
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
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
        ))->activityWorker();

        self::assertInstanceOf(TemporalActivityWorker::class, $worker);
    }

    /**
     * And what it takes to start an execution **on the cluster** without waiting for it:
     * `MagentoRuntime::run()` starts through the same client, then waits (#765).
     */
    public function testAWorkflowCanBeStartedOnTheClusterRatherThanInThisProcess(): void
    {
        $client = (new RuntimeFactory(
            temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0',
        ))->workflowClient();

        self::assertInstanceOf(WorkflowClient::class, $client);
    }

    public function testSearchAttributesAreOffUntilEnvPhpTurnsThemOn(): void
    {
        // #558: a namespace that has not registered them refuses every start that names them.
        $connection = static fn(?bool $enabled): TemporalConnection => (new \ReflectionMethod(RuntimeFactory::class, 'temporalSettings'))
            ->invoke(new RuntimeFactory(temporalDsn: 'temporal://127.0.0.1:7234?namespace=default&tls=0', temporalSearchAttributes: $enabled))
            ?? self::fail('a DSN gives a connection');

        self::assertFalse($connection(null)->searchAttributes);
        self::assertTrue($connection(true)->searchAttributes);
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
        self::assertSame($declared(null), $declared('temporal://127.0.0.1:7234?namespace=default&tls=0'));
    }

    /**
     * Without a DSN, a run that continues as new hands over to the next one, as on every other
     * backend, and the caller gets the last run's result (#802).
     */
    public function testWithoutADsnAContinueAsNewChainRunsToItsLastRun(): void
    {
        $runtime = (new RuntimeFactory(workflowClasses: [CounterWorkflow::class]))->create();

        self::assertSame('done at 2', $runtime->run(CounterWorkflow::class, ['n' => 0]));
    }

    public function testWithoutADsnTheContinueAsNewCapIsConfigurable(): void
    {
        $runtime = (new RuntimeFactory(workflowClasses: [CounterWorkflow::class], maxContinuations: 1))->create();

        $this->expectException(ContinuationCapReachedException::class);

        $runtime->run(CounterWorkflow::class, ['n' => 0]);
    }

    /**
     * A negative cap in di.xml fails when the ObjectManager builds the factory, not at the first
     * `create()` (#900).
     */
    public function testANegativeContinueAsNewCapFailsWhenTheFactoryIsBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxContinuations must be 0 or more, -1 given.');

        new RuntimeFactory(maxContinuations: -1);
    }
}
