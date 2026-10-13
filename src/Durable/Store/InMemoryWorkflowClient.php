<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * The in-memory implementation of {@see WorkflowClientPort}, first slice: start, and the
 * existence and ended checks every operation shares. Signal, query, update, result, cancel and
 * terminate refuse with {@see UnsupportedByBackendException} once the execution is found; tasks
 * 2.1 to 2.5 of the change replace each refusal. Start options are refused until task 2.6.
 */
final class InMemoryWorkflowClient implements WorkflowClientPort
{
    private const BACKEND = 'in-memory';

    public function __construct(
        private readonly WorkflowMetadataStore $metadata,
        private readonly InMemoryWorkflowRunCatalog $catalog,
    ) {}

    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): void
    {
        if (null !== $options) {
            throw UnsupportedByBackendException::forMethod(self::BACKEND, 'start with options', 'start without options until task 2.6 of the workflow-repository change');
        }
        if (!$this->metadata->insertIfAbsent($executionId, $workflowType, $input)) {
            throw new WorkflowExecutionAlreadyStarted($executionId->toString());
        }
        $this->catalog->recordStart($executionId, $workflowType);
    }

    public function signal(ExecutionId $executionId, string $name, array $arguments = []): void
    {
        $this->find($executionId);

        throw $this->notYet('signal');
    }

    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        $this->find($executionId);

        throw $this->notYet('query');
    }

    public function update(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        $this->find($executionId);

        throw $this->notYet('update');
    }

    public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed
    {
        $this->find($executionId);

        throw $this->notYet('result');
    }

    public function cancel(ExecutionId $executionId): void
    {
        $this->findRunning($executionId);

        throw $this->notYet('cancel');
    }

    public function terminate(ExecutionId $executionId, string $reason = ''): void
    {
        $this->findRunning($executionId);

        throw $this->notYet('terminate');
    }

    private function find(ExecutionId $executionId): WorkflowRunDescription
    {
        return $this->catalog->findRun($executionId) ?? throw new WorkflowExecutionNotFound($executionId->toString());
    }

    private function findRunning(ExecutionId $executionId): WorkflowRunDescription
    {
        $run = $this->find($executionId);
        if (WorkflowRunStatus::Running !== $run->status) {
            throw new WorkflowExecutionAlreadyEnded($executionId->toString());
        }

        return $run;
    }

    private function notYet(string $method): UnsupportedByBackendException
    {
        return UnsupportedByBackendException::forMethod(self::BACKEND, $method, 'not implemented yet, see the workflow-repository change');
    }
}
