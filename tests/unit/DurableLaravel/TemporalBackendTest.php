<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Laravel;

use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityHeartbeatSender;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Laravel\DurableServiceProvider;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use unit\DurableLaravel\Fixtures\HeartbeatingActivity;

/**
 * The Temporal backend, served rather than refused.
 *
 * What these tests do not do: talk to a cluster. They check that the package **assembles** the
 * bridge — the integration suite, for its part, runs against a real server.
 */
final class TemporalBackendTest extends TestCase
{
    private const DSN = 'temporal://127.0.0.1:7233?namespace=durable-test'
        . '&journal_task_queue=durable-journal&activity_task_queue=durable-activities';

    public function testTemporalIsOneOfTheBackendsThePackageServes(): void
    {
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);

        (new DurableServiceProvider($app))->register();

        // The journal and the catalog come from the cluster…
        self::assertInstanceOf(TemporalWorkflowRunCatalog::class, $app->make(WorkflowRunCatalogInterface::class));
        // …and the task worker is assembled, ready to be drained by the command.
        self::assertInstanceOf(WorkflowTaskProcessor::class, $app->make(WorkflowTaskProcessor::class));
    }

    public function testTheActivityTaskQueueHasAWorkerToo(): void
    {
        // A workflow that schedules an activity emits a task on the cluster's activity queue. With
        // no worker bound, nothing takes it and the run stops at its first activity (#355).
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);

        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(TemporalActivityWorker::class, $app->make(TemporalActivityWorker::class));
    }

    public function testTheActivityWorkerAndTheActivitiesShareTheTemporalHeartbeatSender(): void
    {
        // The worker binds each task's token onto its sender; an activity that injects the
        // interface must get that very instance, or its heartbeats never reach the cluster (#510).
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);
        (new DurableServiceProvider($app))->register();

        $worker = $app->make(TemporalActivityWorker::class);
        $sender = (new \ReflectionProperty($worker, 'heartbeatSender'))->getValue($worker);
        $processor = (new \ReflectionProperty($worker, 'processor'))->getValue($worker);

        self::assertInstanceOf(TemporalActivityHeartbeatSender::class, $sender);
        self::assertSame($sender, (new \ReflectionProperty($processor, 'heartbeatSender'))->getValue($processor));
        self::assertSame($sender, $app->make(HeartbeatingActivity::class)->heartbeat);
    }

    public function testASenderTheApplicationRebindsIsTheOneTheActivityWorkerUses(): void
    {
        // The activities inject whatever the container binds; the worker must bind the task token
        // onto that same instance, or the heartbeats go nowhere (#510, review of #356).
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);
        (new DurableServiceProvider($app))->register();
        $mine = $this->createMock(ActivityHeartbeatSenderInterface::class);
        $app->singleton(ActivityHeartbeatSenderInterface::class, static fn() => $mine);

        $worker = $app->make(TemporalActivityWorker::class);
        $processor = (new \ReflectionProperty($worker, 'processor'))->getValue($worker);

        self::assertSame($mine, (new \ReflectionProperty($worker, 'heartbeatSender'))->getValue($worker));
        self::assertSame($mine, (new \ReflectionProperty($processor, 'heartbeatSender'))->getValue($processor));
    }

    public function testTheTemporalServicesComeFromOneAssemblyAndTheLoaderReachesThem(): void
    {
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);
        (new DurableServiceProvider($app))->register();

        $assembly = $app->make(TemporalRuntimeAssembly::class);
        self::assertSame($assembly->historyCursor(), $app->make(TemporalHistoryCursor::class));
        self::assertSame($assembly->workflowClient(), $app->make(WorkflowClientInterface::class));
        self::assertSame($assembly->workflowTaskProcessor(), $app->make(WorkflowTaskProcessor::class));
        self::assertSame($assembly->runCatalog(), $app->make(WorkflowRunCatalogInterface::class));

        $loader = $app->make(WorkflowDefinitionLoader::class);
        $read = static fn(object $o, string $p): mixed => (new \ReflectionProperty($o, $p))->getValue($o);
        self::assertSame($loader, $read($app->make(WorkflowTaskRunner::class), 'workflowDefinitionLoader'));
        self::assertSame($loader, $read($app->make(WorkflowClientInterface::class), 'workflowDefinitionLoader'));
    }

    public function testMetadataAndParentLinksStayInMemoryBecauseTheClusterHoldsTheState(): void
    {
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);

        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(InMemoryWorkflowMetadataStore::class, $app->make(WorkflowMetadataStore::class));
    }

    public function testTheDsnIsRequiredAndSaysWhat(): void
    {
        $app = $this->container(['backend' => 'temporal']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('durable.temporal.dsn');
        $this->expectExceptionMessage('two task queues');

        (new DurableServiceProvider($app))->register();
    }

    public function testTheDsnIsParsedIntoAConnection(): void
    {
        $app = $this->container(['backend' => 'temporal', 'temporal' => ['dsn' => self::DSN]]);
        (new DurableServiceProvider($app))->register();

        self::assertInstanceOf(TemporalConnection::class, $app->make(TemporalConnection::class));
    }

    public function testTheApplicationsGuzzleClientIsTheOneTransportGuzzleUses(): void
    {
        // Proxy, TLS, middleware: whatever the application set on its client applies to gRPC.
        $resolved = false;
        $app = $this->container(['backend' => 'temporal', 'temporal' => [
            'dsn' => self::DSN . '&transport=guzzle',
            'guzzle_client' => 'app.guzzle',
        ]]);
        $app->bind('app.guzzle', static function () use (&$resolved): \GuzzleHttp\Client {
            $resolved = true;

            return new \GuzzleHttp\Client();
        });
        (new DurableServiceProvider($app))->register();

        $app->make('durable.temporal.client');

        self::assertTrue($resolved, 'durable.temporal.guzzle_client names the binding the client is built with');
    }

    public function testTheApplicationsPsr18ClientCarriesTheJsonGateway(): void
    {
        $resolved = [];
        $app = $this->container(['backend' => 'temporal', 'temporal' => [
            'dsn' => 'temporal+http://127.0.0.1?namespace=durable-test&journal_task_queue=durable-journal&activity_task_queue=durable-activities',
            'psr18_client' => 'app.psr18',
            'psr17_factory' => 'app.psr17',
        ]]);
        $app->bind('app.psr18', static function () use (&$resolved): \GuzzleHttp\Client {
            $resolved[] = 'client';

            return new \GuzzleHttp\Client();
        });
        $app->bind('app.psr17', static function () use (&$resolved): \GuzzleHttp\Psr7\HttpFactory {
            $resolved[] = 'factory';

            return new \GuzzleHttp\Psr7\HttpFactory();
        });
        (new DurableServiceProvider($app))->register();

        $app->make('durable.temporal.client');

        self::assertEqualsCanonicalizing(['client', 'factory'], $resolved);
    }

    /** @param array<string, mixed> $durable */
    private function container(array $durable): Container
    {
        $app = new Container();
        $app->instance('config', new \ArrayObject(['durable' => $durable], \ArrayObject::ARRAY_AS_PROPS));

        return $app;
    }
}
