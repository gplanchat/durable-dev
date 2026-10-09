<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * The engine, as a Magento process holds it.
 *
 * It does nothing the component does not already do: it holds the five objects
 * together and gives the host the three gestures it needs — declare an activity,
 * declare a workflow, run an execution.
 *
 * `run()` follows the configured backend (#765). Without one, the runner executes
 * the workflow in this process. With one, the factory hands a `$runOnBackend`
 * closure that starts the execution there and waits for its result, as a client
 * does; the backend's workers carry it, not this process. A new backend plugs in
 * the same way, by handing its own closure.
 *
 * What is **absent** is the point. There is no attribute autoconfiguration:
 * Magento's container has no equivalent of Symfony's tags, so a class is
 * declared. That is the cost of Tier 1, and naming it here saves rediscovering
 * it in every class that is surprised by it.
 */
final class MagentoRuntime
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly RegistryActivityExecutor $activities,
        private readonly WorkflowRegistry $workflows,
        /** Null on the database backend, which holds nothing in-process. */
        private readonly ?InMemoryWorkflowRunner $runner,
        /**
         * Null runs in this process. Typed as a closure so no bridge type reaches this signature.
         *
         * @var (\Closure(class-string, array<string, mixed>, string): mixed)|null
         */
        private readonly ?\Closure $runOnBackend = null,
        /** Without a DSN: told of each run `run()` starts in this process, so the catalogue lists it (#985). */
        private readonly ?WorkflowRunProjectionInterface $projection = null,
    ) {}

    /** @var list<string> */
    private array $declaredActivities = [];

    /**
     * @param callable(array<string, mixed>): mixed $handler
     */
    public function registerActivity(string $activityName, callable $handler): void
    {
        $this->activities->register($activityName, $handler);
        $this->declaredActivities[] = $activityName;
    }

    /**
     * @param class-string $workflowClass
     */
    public function registerWorkflow(string $workflowClass): void
    {
        $this->workflows->registerClass($workflowClass);
    }

    /**
     * @param class-string          $workflowClass
     * @param array<string, mixed>  $input
     */
    public function run(string $workflowClass, array $input = [], ?string $executionId = null): mixed
    {
        if (!$this->workflows->has($workflowClass)) {
            throw UndeclaredWorkflowException::forClass($workflowClass);
        }

        $executionId ??= 'magento-' . bin2hex(random_bytes(6));

        if (null !== $this->runOnBackend) {
            return ($this->runOnBackend)($workflowClass, $input, $executionId);
        }

        if (null === $this->runner) {
            throw BackendSelectionException::nothingToRunWith();
        }

        $this->projection?->recordStart(ExecutionId::fromString($executionId), (new WorkflowDefinitionLoader())->aliasForTemporalInterop($workflowClass));

        return $this->runner->run(ExecutionId::fromString($executionId), $this->workflows->getHandler($workflowClass, $input));
    }

    /**
     * The activity names declaration produced, in the order the contracts carry them.
     *
     * This is what makes it possible to say, without reading the code, that the names come from
     * `#[AsActivityMethod]` and not from strings copied out beside them.
     *
     * @return list<string>
     */
    public function declaredActivities(): array
    {
        return $this->declaredActivities;
    }

    public function eventStore(): EventStoreInterface
    {
        return $this->eventStore;
    }
}
