<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowQueryNotFound;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * Answers a query on a journal backend: the workflow is rebuilt by replaying its journal up to its
 * current suspension point, with a command buffer that refuses every write, and the
 * `#[AsQueryMethod]` handler is called on the instance that replay built. Determinism makes the
 * state reached equal to the live one. The pass appends no event.
 *
 * It costs a replay, proportional to the length of the history.
 */
final class ReadOnlyReplay
{
    public function __construct(
        private readonly EventStoreInterface $events,
        private readonly WorkflowMetadataStore $metadata,
        private readonly WorkflowRegistry $registry,
        private readonly ExecutionRuntime $runtime,
    ) {}

    /**
     * @param array<mixed> $arguments
     *
     * @throws WorkflowExecutionNotFound if no start payload is held for the execution
     * @throws WorkflowQueryNotFound     if the workflow declares no query under that name
     */
    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        $row = $this->metadata->get($executionId) ?? throw new WorkflowExecutionNotFound($executionId->toString());

        $context = new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->events, $executionId),
            new ReadOnlyCommandBuffer(),
        );
        (new WorkflowFiberDriver(self::inertLifecycle()))->run(
            $context,
            new WorkflowEnvironment($context, $this->runtime),
            $this->registry->getHandler($row['workflowType'], $row['payload']),
        );

        $handlers = $context->queryHandlers();
        if (!$handlers->has($name)) {
            throw new WorkflowQueryNotFound($executionId->toString(), $name);
        }

        return $handlers->call($name, $arguments);
    }

    /**
     * The outcome of the pass is not recorded anywhere: the pass only builds the instance.
     */
    private static function inertLifecycle(): WorkflowLifecycleInterface
    {
        return new class implements WorkflowLifecycleInterface {
            public function onBeforeRun(ExecutionId $executionId): void {}

            public function isCancellationPending(ExecutionId $executionId): bool
            {
                return false;
            }

            public function onCancellationDelivered(ExecutionId $executionId, array $cancelledOperationIds): void {}

            public function onCancelled(ExecutionId $executionId, WorkflowCancelledFailure $failure): void {}

            public function onCompleted(ExecutionId $executionId, mixed $result): void {}

            public function onSuspended(ExecutionId $executionId, Awaitable $pending): void {}

            public function onContinuedAsNew(ExecutionId $executionId, ContinueAsNewRequested $request): void {}

            public function onFailed(ExecutionId $executionId, \Throwable $failure): void {}
        };
    }
}
