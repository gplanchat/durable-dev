<?php

declare(strict_types=1);

namespace integration\Durable\Support;

use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;

/**
 * Drives a workflow in distributed mode (suspension on every await) for the tests:
 * explicit start / resume and one-at-a-time draining of the activity queue.
 */
final class StepwiseWorkflowHarness
{
    private mixed $lastCompletedResult = null;

    private function __construct(
        private readonly InMemoryEventStore $eventStore,
        private readonly InMemoryActivityTransport $activityTransport,
        private readonly ExecutionRuntime $runtime,
        private readonly ExecutionEngine $engine,
    ) {}

    public static function create(
        InMemoryEventStore $eventStore,
        InMemoryActivityTransport $activityTransport,
        ActivityExecutor $activityExecutor,
    ): self {
        $runtime = new ExecutionRuntime(
            $eventStore,
            $activityTransport,
            $activityExecutor,
            maxActivityRetries: 0,
            clock: null,
            distributed: true,
        );
        $engine = new ExecutionEngine($eventStore, $runtime);

        return new self($eventStore, $activityTransport, $runtime, $engine);
    }

    public function eventStore(): InMemoryEventStore
    {
        return $this->eventStore;
    }

    public function activityTransport(): InMemoryActivityTransport
    {
        return $this->activityTransport;
    }

    public function lastCompletedResult(): mixed
    {
        return $this->lastCompletedResult;
    }

    /**
     * @return bool true if the workflow is suspended (activity or timer pending)
     */
    public function start(string $executionId, callable $handler): bool
    {
        try {
            $this->lastCompletedResult = $this->engine->start($executionId, $handler);

            return false;
        } catch (WorkflowSuspendedException) {
            return true;
        }
    }

    /**
     * @return bool true if still suspended after this resume
     */
    public function resume(string $executionId, callable $handler): bool
    {
        try {
            $this->lastCompletedResult = $this->engine->resume($executionId, $handler);

            return false;
        } catch (WorkflowSuspendedException) {
            return true;
        }
    }

    /**
     * Executes at most one activity present in the queue (one dequeue + completion + journal).
     */
    public function drainOneQueuedActivity(string $executionId): bool
    {
        if ($this->activityTransport->isEmpty()) {
            return false;
        }
        $context = new ExecutionContext($executionId, $this->eventStore, $this->activityTransport, null);
        $this->runtime->drainActivityQueueOnce($context);

        return true;
    }
}
