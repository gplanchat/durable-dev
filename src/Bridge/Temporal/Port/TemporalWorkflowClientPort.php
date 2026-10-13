<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Port;

use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowResultTimeout;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * {@see WorkflowClientPort} on Temporal, keyed by {@see ExecutionId}. Every start option is honoured
 * by the server, so none is refused.
 */
final readonly class TemporalWorkflowClientPort implements WorkflowClientPort
{
    private const GRPC_NOT_FOUND = 5;
    private const POLL_INTERVAL_MS = 500;

    public function __construct(private WorkflowClient $client) {}

    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId
    {
        return $this->client->start($executionId, $workflowType, $input, $options);
    }

    public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void
    {
        $this->refusing($executionId, fn() => $this->client->signal($this->client->workflowId($executionId), $name, $arguments, $requestId));
    }

    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        try {
            return $this->client->query($this->client->workflowId($executionId), $name, $arguments);
        } catch (\RuntimeException $failure) {
            // A closed execution still answers a query: NotFound means the server holds none.
            throw self::GRPC_NOT_FOUND === $failure->getCode() ? new WorkflowExecutionNotFound($executionId->toString(), $failure) : $failure;
        }
    }

    public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed
    {
        return $this->refusing($executionId, fn() => $this->client->update($this->client->workflowId($executionId), $name, $arguments, $updateId));
    }

    public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed
    {
        // The close event of an unknown workflow reads as "not closed yet": ask first.
        if (!$this->client->hasExecution($executionId)) {
            throw new WorkflowExecutionNotFound($executionId->toString());
        }
        $unbounded = null === $timeout || $timeout->isInfinite();
        $ms = $unbounded ? 0 : (int) ceil($timeout->toSeconds() * 1000.0);
        $interval = $unbounded ? self::POLL_INTERVAL_MS : max(10, min(self::POLL_INTERVAL_MS, $ms));
        $polls = $unbounded ? \PHP_INT_MAX : intdiv($ms + $interval - 1, $interval) + 1;

        try {
            return $this->client->pollForCompletion($executionId->toString(), $interval, $polls);
        } catch (WorkflowStuckException $stuck) {
            throw new WorkflowResultTimeout($executionId->toString(), $timeout ?? Duration::infinity(), $stuck);
        }
    }

    public function cancel(ExecutionId $executionId, ?string $requestId = null): void
    {
        $this->client->cancel($executionId, $requestId);
    }

    public function terminate(ExecutionId $executionId, string $reason = ''): void
    {
        $this->client->terminate($executionId, $reason);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $call
     *
     * @return T
     */
    private function refusing(ExecutionId $executionId, \Closure $call): mixed
    {
        try {
            return $call();
        } catch (\RuntimeException $failure) {
            throw $this->client->refusalOf($executionId, $failure);
        }
    }
}
