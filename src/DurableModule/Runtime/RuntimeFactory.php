<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Bridge\Temporal\Http\Psr18Http;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\TemporalJournalEventStore;
use Gplanchat\Bridge\Temporal\TemporalRuntimeAssembly;
use Gplanchat\Bridge\Temporal\Worker\TemporalActivityWorker;
use Gplanchat\Bridge\Temporal\Worker\WorkflowTaskProcessor;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Activity\PayloadToContractMethodInvoker;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;
use Magento\Framework\App\DeploymentConfig;
use Psr\Log\LoggerInterface;

/**
 * Assembles the engine for a Magento process.
 *
 * Five objects, no framework: it is exactly what `WorkflowTestEnvironment`
 * assembles for a test, and it is what makes a Tier 1 host possible at all. The
 * component asks for neither a container nor a bus; what a host has to supply is
 * somewhere to put those five and a way to reach them.
 *
 * This factory is deliberately an ordinary Magento object rather than a `di.xml`
 * that would wire the five: the runner takes two scalars on top of the four
 * dependencies, and expressing them as `<argument>` would have moved them away
 * from the one line that explains what they bound.
 */
/*
 * Not `final`: Magento generates an `Interceptor` extending every class its
 * container instantiates, to carry the plugins. A final class makes container
 * compilation fail — "cannot extend final class" — and the message does not say
 * that the keyword is to blame. The house style writes `final` everywhere; here
 * the host forbids it, and saying so beats leaving it to be guessed.
 */
class RuntimeFactory
{
    /**
     * @param list<class-string> $workflowClasses    The classes carrying `#[AsWorkflow]`, declared
     *                                              by name: Magento's container has no Symfony
     *                                              tags, so nothing picks them up on its own.
     * @param list<object>       $activityHandlers   The activity handlers. **Their contract is not
     *                                              declared**: their interfaces are read and those
     *                                              carrying `#[AsActivityMethod]` are kept. One
     *                                              declaration fewer to get wrong.
     * @param int                $maxActivityRetries Ceiling when an activity sets none. `0` caps
     *                                              nothing — and an activity without a
     *                                              `RetryLimit` retries indefinitely, which is
     *                                              Temporal's default.
     * @param float              $budgetSeconds      Global bound on an execution. It exists
     *                                              because the previous point makes "it never
     *                                              finishes" reachable without an error.
     */
    private const TEMPORAL_DSN_CONFIG_PATH = 'durable/temporal/dsn';

    /**
     * How many executions the admin screens read at once.
     *
     * ⚠ **One window only, for the grid as for the detail.** They were two distinct literals, and
     * two windows of different sizes make it possible to be listed on one side and not found on
     * the other — a link that leads to "unknown execution" from the very row that just named it.
     *
     * ponytail: a bounded window because the grid pages by offset and the backend by continuation
     * cursor, and the two do not translate without state. The day it gets in the way, the way out
     * is to remember the cursors per page in the administrator's session — not to enlarge the
     * window.
     */
    public const OBSERVATION_WINDOW = 200;

    public function __construct(
        private readonly array $workflowClasses = [],
        private readonly array $activityHandlers = [],
        private readonly ?string $temporalDsn = null,
        /**
         * Read from `env.php`, beside `lock` and `queue`: that is where Magento puts what has to
         * be readable before a database answers. Nullable and absent by default so the factory
         * stays constructible **without Magento** — which is what puts the backend decision under
         * CI's guard, where the rest of the module asks for a bench.
         */
        private readonly ?DeploymentConfig $deploymentConfig = null,
        private readonly int $maxActivityRetries = 0,
        private readonly float $budgetSeconds = InMemoryWorkflowRunner::DEFAULT_BUDGET_SECONDS,
        private readonly ?LoggerInterface $logger = null,
        /**
         * The application's Guzzle client, for `transport=guzzle` in the DSN; any other transport
         * ignores it. Set in `di.xml` with an `<argument name="guzzle" xsi:type="object">`; null
         * builds a default client.
         */
        private readonly ?\GuzzleHttp\ClientInterface $guzzle = null,
        /**
         * The application's PSR-18 client and its PSR-17 factory, for `transport=http` (the JSON
         * gateway) instead of curl; any other transport ignores it. Set in `di.xml` with an
         * `<argument name="jsonGateway" xsi:type="object">`.
         */
        private readonly ?Psr18Http $jsonGateway = null,
        /**
         * The sender the activities inject, from `di.xml`'s preference. On Temporal it is pointed
         * at the worker's sender, so their heartbeats carry the task's token (#510).
         */
        private readonly ?SharedActivityHeartbeatSender $heartbeat = null,
    ) {}

    /** One per factory, and the ObjectManager shares the factory: one gRPC client per request (#356). */
    private ?WorkflowServiceClientInterface $client = null;

    private ?TemporalRuntimeAssembly $assembly = null;

    public function create(): MagentoRuntime
    {
        $eventStore = $this->eventStore();
        $transport = new InMemoryActivityTransport();
        $activities = new RegistryActivityExecutor();
        $workflows = new WorkflowRegistry();

        $runtime = new MagentoRuntime(
            $eventStore,
            $activities,
            $workflows,
            new InMemoryWorkflowRunner(
                $eventStore,
                $transport,
                $activities,
                $this->maxActivityRetries,
                $workflows,
                $this->budgetSeconds,
            ),
        );

        foreach ($this->workflowClasses as $workflowClass) {
            $runtime->registerWorkflow($workflowClass);
        }

        // Through the runtime and not the executor: it is the runtime that holds the list the
        // screen and the demonstration command render.
        foreach ($this->activityBindings() as $activityName => $invoker) {
            $runtime->registerActivity($activityName, $invoker);
        }

        return $runtime;
    }

    /**
     * Where the journal lives, and who decides.
     *
     * §2.3 removed the backend configuration surface: so it is not a copied-out name that
     * chooses, it is **the presence of a DSN** under `durable/temporal/dsn` in `env.php`. Absent,
     * the journal lives in this process and dies with it — a legitimate choice for a command, and
     * ruinous for a consumer. Present, it lives in the cluster, and it is the only persistent
     * journal Magento reaches: the host ships neither of the two connection types the SQL bridges
     * bind to.
     */
    private function eventStore(): EventStoreInterface
    {
        $settings = $this->temporalSettings();

        return $settings === null
            ? new InMemoryEventStore()
            : new TemporalJournalEventStore($this->client($settings), $settings);
    }

    /**
     * What the admin screen queries, and why it is not the event store.
     *
     * A catalog is **not derived** from a journal: `InMemoryWorkflowRunCatalog` holds its own map,
     * fed by `recordStart()`/`recordOutcome()` in the process that executes. An admin request
     * executes nothing — so it has nothing to read there, and a grid built on it is empty without
     * being broken. Listing a cluster's executions means asking the cluster, and the bridge
     * already ships the class that knows how.
     */
    public function catalog(): WorkflowRunCatalogInterface
    {
        $settings = $this->temporalSettings();

        if ($settings === null) {
            return new InMemoryWorkflowRunCatalog(new InMemoryEventStore());
        }

        // The history cursor is not decorative: `listRuns()` returns only the Temporal workflow's
        // status — the journal's, which is **long by construction** and therefore eternally
        // `running`. What tells a finished execution apart from a running one is read in its
        // events, and the assembly's catalog reads them through the cursor.
        return $this->assembly($settings)->runCatalog();
    }

    public function journalWorker(): WorkflowTaskProcessor
    {
        $settings = $this->temporalSettings();

        if ($settings === null) {
            throw new \RuntimeException(
                'A journal worker needs a cluster to poll. Set durable/temporal/dsn in app/etc/env.php first — without it the journal lives in the process that writes it, and a worker would poll a queue that does not exist while looking perfectly healthy.',
            );
        }

        return $this->assembly($settings)->workflowTaskProcessor();
    }

    public function activityWorker(): TemporalActivityWorker
    {
        $assembly = $this->assembly($this->requireCluster('An activity worker'));
        // The activities inject the shared sender; it now leads to the one the worker binds (#510).
        $this->heartbeat?->delegateTo($assembly->heartbeatSender());

        return $assembly->scratchActivityWorker($this->activityExecutor());
    }

    public function workflowClient(): WorkflowClient
    {
        return $this->assembly($this->requireCluster('Starting a workflow on the cluster'))->workflowClient();
    }

    private function client(TemporalConnection $settings): WorkflowServiceClientInterface
    {
        return $this->client ??= WorkflowServiceClientFactory::create($settings, $this->logger, $this->guzzle, $this->jsonGateway);
    }

    private function assembly(TemporalConnection $settings): TemporalRuntimeAssembly
    {
        if (null === $this->assembly) {
            $registry = new WorkflowRegistry();
            foreach ($this->workflowClasses as $workflowClass) {
                $registry->registerClass($workflowClass);
            }
            $this->assembly = new TemporalRuntimeAssembly($this->client($settings), $settings, $registry, new WorkflowDefinitionLoader());
        }

        return $this->assembly;
    }

    private function requireCluster(string $what): TemporalConnection
    {
        $settings = $this->temporalSettings();

        if ($settings === null) {
            throw new \RuntimeException(\sprintf(
                '%s needs a cluster. Set durable/temporal/dsn in app/etc/env.php first — without it the journal lives in the process that writes it, and a worker would poll a queue that does not exist while looking perfectly healthy.',
                $what,
            ));
        }

        return $settings;
    }

    /**
     * `null` when no DSN is configured: the journal then lives in this process.
     */
    private function temporalSettings(): ?TemporalConnection
    {
        $dsn = $this->temporalDsn ?? $this->configuredDsn();

        if ($dsn === null || $dsn === '') {
            return null;
        }

        if (!\class_exists(TemporalConnection::class)) {
            throw new \RuntimeException(\sprintf(
                'A Temporal DSN is configured under durable/temporal/dsn, but %s is not installed. Run `composer require gplanchat/durable-bridge-temporal`, or remove the DSN to keep the journal in the process.',
                'gplanchat/durable-bridge-temporal',
            ));
        }

        return TemporalConnection::fromDsn($dsn);
    }

    private function configuredDsn(): ?string
    {
        $configured = $this->deploymentConfig?->get(self::TEMPORAL_DSN_CONFIG_PATH);

        return \is_string($configured) ? $configured : null;
    }

    /**
     * The Magento counterpart of the bundle's compiler pass: the same two core objects,
     * `ActivityContractResolver` for the names and `PayloadToContractMethodInvoker` for the call.
     * All that changes is where the list comes from — a `di.xml` argument rather than a tag.
     *
     * The same executor serves the in-process engine and the activity worker: they are the same
     * activities, resolved once, whoever calls them. That is what guarantees a worker executes
     * exactly what the module declared, and nothing else.
     */
    private function activityExecutor(): RegistryActivityExecutor
    {
        $activities = new RegistryActivityExecutor();

        foreach ($this->activityBindings() as $activityName => $invoker) {
            $activities->register($activityName, $invoker);
        }

        return $activities;
    }

    /**
     * The declared activities, resolved just once: the in-process engine and the worker both read
     * them from here, so they necessarily execute the same thing.
     *
     * @return array<string, callable(array<string, mixed>): mixed>
     */
    private function activityBindings(): array
    {
        $resolver = new ActivityContractResolver();
        $bindings = [];

        foreach ($this->activityHandlers as $handler) {
            foreach (\class_implements($handler) ?: [] as $contract) {
                foreach ($resolver->resolveActivityMethods($contract) as $method => $activityName) {
                    $bindings[$activityName] = new PayloadToContractMethodInvoker($handler, $contract, $method);
                }
            }
        }

        return $bindings;
    }
}
