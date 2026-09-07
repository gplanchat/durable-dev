<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel;

use Gplanchat\Bridge\Illuminate\Queue\ResumeLock;
use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Bridge\Illuminate\Store\IlluminateChildWorkflowParentLinkStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateEventStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowMetadataStore;
use Gplanchat\Bridge\Illuminate\Store\IlluminateWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceNexusRpc;
use Gplanchat\Bridge\Temporal\Store\TemporalReadThroughEventStore;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\Worker\TemporalNexusWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Durable\Activity\NullActivityHeartbeatSender;
use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Laravel\Nexus\DeclaredNexusOperations;
use Gplanchat\Durable\Laravel\Queue\LaravelActivityTransport;
use Gplanchat\Durable\Laravel\Queue\LaravelWorkflowResumeDispatcher;
use Gplanchat\Durable\Laravel\Queue\LaravelWorkflowTimerDispatcher;
use Gplanchat\Durable\Laravel\Queue\ResumeDeferral;
use Gplanchat\Durable\Laravel\Workflow\DeclaredWorkflowTypes;
use Gplanchat\Durable\Nexus\Serving\NexusOperationRegistry;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\NullWorkflowTimerDispatcher;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryChildWorkflowParentLinkStore;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Worker\ActivityMessageProcessor;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the four storage ports from a single configuration file.
 *
 * **One choice of backend binds all four ports together.** A journal on one backend and metadata on
 * another is not a configuration, it is a fault — hence a single `match` rather than four
 * independent settings.
 *
 * This provider is the **integration** package's. The bridge's own,
 * `Gplanchat\Bridge\Illuminate\DurableIlluminateServiceProvider`, only says where its migrations
 * are, and the two load side by side without stepping on each other.
 */
final class DurableServiceProvider extends ServiceProvider
{
    private const BACKENDS = ['illuminate', 'memory', 'temporal'];

    public function register(): void
    {
        $config = $this->durableConfig();
        $backend = $config['backend'] ?? 'illuminate';

        if (!\in_array($backend, self::BACKENDS, true)) {
            // Name both: a message that only says "unknown backend" makes someone open the code.
            throw new \InvalidArgumentException(\sprintf(
                'Durable: unknown backend "%s". This package serves %s.',
                \is_scalar($backend) ? (string) $backend : \get_debug_type($backend),
                '"' . implode('", "', self::BACKENDS) . '"',
            ));
        }

        match ($backend) {
            'illuminate' => $this->bindIlluminate($config),
            'temporal' => $this->bindTemporal($config),
            default => $this->bindInMemory(),
        };
        $this->bindActivityTransport($backend, $config);
        $this->bindResumeLock($config);
        $this->bindWorkflowRegistry($config);
        $this->bindResumePath($config);
        $this->bindResumeDeferral($config);
        $this->bindNexus($backend, $config);
    }

    public function boot(): void
    {
        // `ServiceProvider::$app` is documented as the full application, and this package insists
        // that this be false: a bare container must be able to register these bindings, in a
        // standalone worker as in a test. Only publishing needs `configPath()`.
        if (method_exists($this->app, 'configPath')) {
            $this->publishes(
                [__DIR__ . '/config/durable.php' => $this->app->configPath('durable.php')],
                'durable-config',
            );
        }

        // §1.3: `null` never locks, in any deployment. The refusal is therefore risk-free at
        // boot, where `array` — correct inside a single process, and the default cache of the test
        // environment — can only be judged by the worker command.
        if ($this->app->bound('cache')) {
            $this->refuseALockStoreThatCannotLock();
        }

        // And `sync` runs the job on the spot: a resume that dispatches another one would recurse
        // in the same process. The Symfony counterpart protects itself with a
        // DispatchAfterCurrentBusStamp; here, it is the connection that must be a real queue.
        $this->refuseAQueueThatRunsInline();
    }

    /** @return array<string, mixed> */
    private function durableConfig(): array
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__ . '/config/durable.php';

        if (!$this->app->bound('config')) {
            return $defaults;
        }

        /** @var array<string, mixed> $configured */
        $configured = $this->app['config']['durable'] ?? [];

        return array_replace_recursive($defaults, $configured);
    }

    /** @param array<string, mixed> $config */
    private function bindIlluminate(array $config): void
    {
        $tables = $config['tables'] ?? [];

        if (!$this->app->bound(Connection::class)) {
            $this->app->singleton(
                Connection::class,
                fn($app) => $app->make('db')->connection($config['connection'] ?? null),
            );
        }

        $this->app->singleton(DurableSchema::class, fn($app) => new DurableSchema(
            $app->make(Connection::class),
            $tables['events'] ?? 'durable_events',
            $tables['metadata'] ?? 'durable_workflow_metadata',
            $tables['parent_links'] ?? 'durable_child_workflow_parent_link',
            $tables['runs'] ?? 'durable_workflow_runs',
        ));

        $this->app->singleton(EventStoreInterface::class, fn($app) => new IlluminateEventStore(
            $app->make(Connection::class),
            $app->make(DurableSchema::class),
            $tables['events'] ?? 'durable_events',
        ));

        $this->app->singleton(WorkflowMetadataStore::class, fn($app) => new IlluminateWorkflowMetadataStore(
            $app->make(Connection::class),
            $app->make(DurableSchema::class),
            $tables['metadata'] ?? 'durable_workflow_metadata',
        ));

        $this->app->singleton(ChildWorkflowParentLinkStoreInterface::class, fn($app) => new IlluminateChildWorkflowParentLinkStore(
            $app->make(Connection::class),
            $app->make(DurableSchema::class),
            $tables['parent_links'] ?? 'durable_child_workflow_parent_link',
        ));

        $this->app->singleton(WorkflowRunCatalogInterface::class, fn($app) => new IlluminateWorkflowRunCatalog(
            $app->make(Connection::class),
            $app->make(DurableSchema::class),
            $tables['runs'] ?? 'durable_workflow_runs',
        ));
    }

    /**
     * The Temporal backend: the journal and the catalog live in the cluster.
     *
     * The metadata and the parent links stay in memory, as on the Symfony side — Temporal holds the
     * durable state, those two are nothing but process cache.
     *
     * **What this package does not replicate, and that is deliberate:** the bridge's Messenger
     * transports. The activities and the resumes go on travelling on the application's queue, which
     * already drains them; Temporal owns the journal, Laravel owns the queue. The workflow task
     * worker, for its part, has its own loop turn — `durable:temporal-worker`.
     *
     * @param array<string, mixed> $config
     */
    private function bindTemporal(array $config): void
    {
        /** @var array<string, mixed> $temporal */
        $temporal = $config['temporal'] ?? [];
        $dsn = $temporal['dsn'] ?? null;

        if (!\is_string($dsn) || '' === $dsn) {
            throw new \InvalidArgumentException(
                'Durable: the "temporal" backend needs durable.temporal.dsn — the address of the '
                . 'cluster, its namespace and its two task queues. Nothing else can supply it.',
            );
        }

        if (!class_exists(TemporalConnection::class)) {
            throw new \InvalidArgumentException(
                'Durable: the "temporal" backend needs gplanchat/durable-bridge-temporal, which is '
                . 'suggested rather than required — it installs a gRPC client and five Symfony '
                . 'components a Laravel application never loads. Run: composer require '
                . 'gplanchat/durable-bridge-temporal',
            );
        }

        $this->app->singleton(TemporalConnection::class, fn() => TemporalConnection::fromDsn($dsn));
        $this->app->singleton(
            'durable.temporal.client',
            fn($app) => WorkflowServiceClientFactory::create($app->make(TemporalConnection::class)),
        );
        $this->app->singleton(TemporalHistoryCursor::class, fn($app) => new TemporalHistoryCursor(
            $app->make('durable.temporal.client'),
            $app->make(TemporalConnection::class),
        ));

        $this->app->singleton(WorkflowRunCatalogInterface::class, fn($app) => new TemporalWorkflowRunCatalog(
            $app->make('durable.temporal.client'),
            $app->make(TemporalConnection::class),
            $app->make(TemporalHistoryCursor::class),
        ));

        $this->app->singleton(WorkflowTaskRunner::class, fn($app) => new WorkflowTaskRunner(
            $app->make(TemporalHistoryCursor::class),
            $app->make(WorkflowRegistry::class),
            $app->make(TemporalConnection::class),
            $app->make(WorkflowDefinitionLoader::class),
        ));

        $this->app->singleton(WorkflowTaskProcessor::class, fn($app) => new WorkflowTaskProcessor(
            $app->make('durable.temporal.client'),
            $app->make(TemporalConnection::class),
            $app->make(WorkflowTaskRunner::class),
        ));

        $this->app->singleton(WorkflowServiceExecutionRpc::class, fn($app) => new WorkflowServiceExecutionRpc(
            $app->make('durable.temporal.client'),
        ));

        $this->app->singleton(WorkflowServiceNexusRpc::class, fn($app) => new WorkflowServiceNexusRpc(
            $app->make('durable.temporal.client'),
        ));

        $this->app->singleton(WorkflowClientInterface::class, fn($app) => new WorkflowClient(
            $app->make('durable.temporal.client'),
            $app->make(TemporalConnection::class),
            $app->make(TemporalHistoryCursor::class),
            $app->make(WorkflowServiceExecutionRpc::class),
            $app->make(WorkflowDefinitionLoader::class),
        ));

        // The journal reads through to the cluster, with an in-memory store for the current turn.
        $this->app->singleton(EventStoreInterface::class, fn($app) => new TemporalReadThroughEventStore(
            new InMemoryEventStore(),
            $app->make(TemporalHistoryCursor::class),
            $app->make(WorkflowClientInterface::class),
        ));

        $this->app->singleton(WorkflowMetadataStore::class, fn() => new InMemoryWorkflowMetadataStore());
        $this->app->singleton(
            ChildWorkflowParentLinkStoreInterface::class,
            fn() => new InMemoryChildWorkflowParentLinkStore(),
        );

        if (method_exists($this->app, 'runningInConsole') && $this->app->runningInConsole()) {
            // Named by a string, and not by `::class`: the class extends
            // `Illuminate\Console\Command`, which cannot enter the root's graph without making
            // the Symfony 6.4 line unresolvable — see phpstan.neon. A `::class` reference would
            // make the analyser follow it into a class it cannot read.
            $this->commands([
                'Gplanchat\\Durable\\Laravel\\Console\\TemporalWorkerCommand',
                'Gplanchat\\Durable\\Laravel\\Console\\NexusWorkerCommand',
            ]);
        }
    }

    private function bindInMemory(): void
    {
        $this->app->singleton(EventStoreInterface::class, fn() => new InMemoryEventStore());
        $this->app->singleton(WorkflowMetadataStore::class, fn() => new InMemoryWorkflowMetadataStore());
        $this->app->singleton(ChildWorkflowParentLinkStoreInterface::class, fn() => new InMemoryChildWorkflowParentLinkStore());
        $this->app->singleton(
            WorkflowRunCatalogInterface::class,
            fn($app) => new InMemoryWorkflowRunCatalog($app->make(EventStoreInterface::class)),
        );
    }

    /**
     * The transport follows the backend, like the four stores: "memory" does not leave the
     * process, "illuminate" travels on the queue the application already drains.
     *
     * @param array<string, mixed> $config
     */
    private function bindActivityTransport(string $backend, array $config): void
    {
        if ($backend !== 'illuminate') {
            $this->app->singleton(ActivityTransportInterface::class, fn() => new InMemoryActivityTransport());
            $this->app->singleton(WorkflowResumeDispatcher::class, fn() => new NullWorkflowResumeDispatcher());

            return;
        }

        /** @var array<string, mixed> $queue */
        $queue = $config['queue'] ?? [];

        $this->app->singleton(ActivityTransportInterface::class, fn($app) => new LaravelActivityTransport(
            $app->make(QueueFactory::class),
            $queue['connection'] ?? null,
            $queue['name'] ?? null,
        ));

        $this->app->singleton(WorkflowResumeDispatcher::class, fn($app) => new LaravelWorkflowResumeDispatcher(
            $app->make(QueueFactory::class),
            $app->make(WorkflowMetadataStore::class),
            $queue['connection'] ?? null,
            $queue['name'] ?? null,
        ));
    }

    /** @param array<string, mixed> $config */
    private function bindWorkflowRegistry(array $config): void
    {
        /** @var list<class-string> $declared */
        $declared = $config['workflows'] ?? [];

        $this->app->singleton(WorkflowRegistry::class, static function () use ($declared): WorkflowRegistry {
            $registry = new WorkflowRegistry();

            foreach ($declared as $workflowClass) {
                // The registry indexes each class twice: under the name its attribute declares
                // and under its FQCN. A resume that has only one of the two still resolves.
                $registry->registerClass($workflowClass);
            }

            return $registry;
        });

        $this->app->singleton(DeclaredWorkflowTypes::class, fn($app) => new DeclaredWorkflowTypes(
            $app->make(WorkflowRegistry::class),
            $declared,
        ));
    }

    /**
     * What replays an execution, and it is the core that does it.
     *
     * `ResumeWorkflowHandler` left the Symfony bundle for the core so that a host without a bus
     * could provide it: this package therefore only has to assemble it, not to rewrite it. A timer,
     * for its part, is a deferred resume — the queue carries the delay, like Messenger's
     * `DelayStamp`.
     *
     * @param array<string, mixed> $config
     */
    private function bindResumePath(array $config): void
    {
        /** @var array<string, mixed> $queue */
        $queue = $config['queue'] ?? [];

        $this->app->singleton(RegistryActivityExecutor::class, fn() => new RegistryActivityExecutor());
        // The port, not only the class: `RunActivityJob` asks for an `ActivityMessageProcessor`,
        // which asks for an `ActivityExecutor`. Without this line the container tries to
        // instantiate an interface, and the activity fails on the first attempt.
        $this->app->singleton(ActivityExecutor::class, fn($app) => $app->make(RegistryActivityExecutor::class));
        $this->app->singleton(WorkflowDefinitionLoader::class, fn() => new WorkflowDefinitionLoader());

        // The timer follows the backend, like the transport and the resume dispatcher: in memory,
        // the drain is inside the process and has nobody to wake.
        $this->app->singleton(
            WorkflowTimerDispatcher::class,
            ($config['backend'] ?? 'illuminate') === 'illuminate'
                ? fn($app) => new LaravelWorkflowTimerDispatcher(
                    $app->make(QueueFactory::class),
                    $queue['connection'] ?? null,
                    $queue['name'] ?? null,
                )
                : fn() => new NullWorkflowTimerDispatcher(),
        );

        $this->app->singleton(ExecutionRuntime::class, fn($app) => new ExecutionRuntime(
            $app->make(EventStoreInterface::class),
            $app->make(ActivityTransportInterface::class),
            $app->make(RegistryActivityExecutor::class),
            // Attempts are unlimited by default, Temporal semantics; `distributed: true` because
            // here the drain is not inside the process, it is `queue:work`.
            0,
            null,
            true,
        ));

        $this->app->singleton(ExecutionEngine::class, fn($app) => new ExecutionEngine(
            $app->make(EventStoreInterface::class),
            $app->make(ExecutionRuntime::class),
        ));

        $this->app->singleton(ActivityMessageProcessor::class, fn($app) => new ActivityMessageProcessor(
            $app->make(EventStoreInterface::class),
            $app->make(ActivityTransportInterface::class),
            $app->make(ActivityExecutor::class),
            $app->make(WorkflowResumeDispatcher::class),
            // No heartbeat: that is a capability of Temporal, and nothing here serves it.
            new NullActivityHeartbeatSender(),
            // Unlimited attempts by default, Temporal semantics. Each activity's own policy wins
            // when it declares one.
            0,
        ));

        $this->app->singleton(ResumeWorkflowHandler::class, fn($app) => new ResumeWorkflowHandler(
            $app->make(ExecutionEngine::class),
            $app->make(WorkflowRegistry::class),
            $app->make(WorkflowMetadataStore::class),
            $app->make(WorkflowResumeDispatcher::class),
            $app->make(EventStoreInterface::class),
            $app->make(ChildWorkflowParentLinkStoreInterface::class),
            $app->make(WorkflowTimerDispatcher::class),
            $app->make(WorkflowDefinitionLoader::class),
        ));
    }

    /** @param array<string, mixed> $config */
    private function bindResumeLock(array $config): void
    {
        /** @var array<string, mixed> $lock */
        $lock = $config['lock'] ?? [];

        $this->app->singleton(ResumeLock::class, fn($app) => new ResumeLock(
            $app->make('cache')->store($lock['store'] ?? null)->getStore(),
            (int) ($lock['ttl'] ?? 300),
            (int) ($lock['wait'] ?? 10),
        ));
    }

    /**
     * Nexus: the registry always exists, and it knows how to say why it cannot route.
     *
     * `routedBy('temporal')` under Temporal, `unavailableOn($backend)` elsewhere — and the second
     * refuses **at registration**, not on the first call. It is the core that carries this refusal,
     * precisely because Symfony's compiler pass only catches Symfony: a host that declares a
     * handler on a backend that does not route must be told the reason, wherever it is.
     *
     * @param array<string, mixed> $config
     */
    private function bindNexus(string $backend, array $config): void
    {
        /** @var array<string, mixed> $nexus */
        $nexus = $config['nexus'] ?? [];
        /** @var array<class-string, class-string> $handlers */
        $handlers = $nexus['handlers'] ?? [];
        /** @var list<class-string> $workflows */
        $workflows = $config['workflows'] ?? [];

        $this->app->singleton(NexusOperationRegistry::class, function ($app) use ($backend, $handlers, $workflows) {
            $registry = 'temporal' === $backend
                ? NexusOperationRegistry::routedBy('temporal')
                : NexusOperationRegistry::unavailableOn($backend);

            (new DeclaredNexusOperations($app, $handlers, $workflows))->registerInto($registry);

            return $registry;
        });

        if ('temporal' !== $backend) {
            return;
        }

        $this->app->singleton(TemporalNexusWorker::class, fn($app) => new TemporalNexusWorker(
            $app->make(WorkflowServiceNexusRpc::class),
            $app->make(TemporalConnection::class),
            $app->make(NexusOperationRegistry::class),
        ));
    }

    /** @param array<string, mixed> $config */
    private function bindResumeDeferral(array $config): void
    {
        /** @var array<string, mixed> $lock */
        $lock = $config['lock'] ?? [];
        /** @var array<string, mixed> $queue */
        $queue = $config['queue'] ?? [];

        $this->app->singleton(ResumeDeferral::class, fn() => new ResumeDeferral(
            (int) ($lock['backoff'] ?? 1),
            (int) ($lock['max_deferrals'] ?? 50),
            $queue['connection'] ?? null,
            $queue['name'] ?? null,
        ));
    }

    private function refuseAQueueThatRunsInline(): void
    {
        $config = $this->durableConfig();
        if (($config['backend'] ?? 'illuminate') !== 'illuminate') {
            return;
        }

        /** @var array<string, mixed> $queue */
        $queue = $config['queue'] ?? [];
        $name = $queue['connection'] ?? null;

        // The **driver name**, not the connection class: `SyncQueue` lives in `illuminate/queue`,
        // from which Laravel 11+ pulls `symfony/process ^7.2` — requiring it would make this
        // package irreconcilable with the Symfony 6.4 line the repository's matrix still tests.
        // Reading the configuration says the same thing, without the dependency, and without
        // having to resolve the connection in order to judge it.
        if ($this->driverOf($name) === 'sync') {
            throw new \InvalidArgumentException(\sprintf(
                'Durable: the "%s" queue connection runs jobs inline, so a resume that dispatches '
                . 'another resume recurses in the same process until the stack ends. Use a real '
                . 'queue connection — database, redis, sqs, beanstalkd.',
                $name ?? 'default',
            ));
        }
    }

    private function driverOf(?string $connection): ?string
    {
        if (!$this->app->bound('config')) {
            return null;
        }

        /** @var array<string, mixed> $queueConfig */
        $queueConfig = $this->app['config']['queue'] ?? [];
        $name = $connection ?? ($queueConfig['default'] ?? null);
        /** @var array<string, array<string, mixed>> $connections */
        $connections = $queueConfig['connections'] ?? [];
        $driver = $connections[$name]['driver'] ?? null;

        return \is_string($driver) ? $driver : null;
    }

    private function refuseALockStoreThatCannotLock(): void
    {
        /** @var array<string, mixed> $lock */
        $lock = $this->durableConfig()['lock'] ?? [];
        $name = $lock['store'] ?? null;

        $store = $this->app->make('cache')->store($name)->getStore();

        // §1.3 let `array` through at boot because it is Laravel's default test cache, and because
        // excluding inside a single process is what a test wants. Under the `illuminate` backend,
        // that can no longer be true: the resume runs in a worker separate from the process that
        // dispatched it, so two "array" locks never see each other.
        if ($store instanceof ArrayStore && ($this->durableConfig()['backend'] ?? 'illuminate') === 'illuminate') {
            throw new \InvalidArgumentException(\sprintf(
                'Durable: the "%s" cache store only excludes inside one process, and a resume runs '
                . 'in a worker separate from whatever dispatched it — two workers would replay the '
                . 'same execution. Use database, redis, memcached, dynamodb or file.',
                $name ?? 'default',
            ));
        }

        if ($store instanceof NullStore) {
            throw new \InvalidArgumentException(\sprintf(
                'Durable: the "%s" cache store grants every lock, so two workers would replay the '
                . 'same execution and its activities would run twice. Use database, redis, '
                . 'memcached, dynamodb or file.',
                $name ?? 'default',
            ));
        }
    }
}
