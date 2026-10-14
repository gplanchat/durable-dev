<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * The client port the ObjectManager hands out (`di.xml` prefers it). The backend is chosen by
 * {@see RuntimeFactory} from `env.php`, which no `di.xml` can read, so each call leads to the port
 * of the backend the factory assembles. A repository declared in a project takes
 * {@see WorkflowClientPort} and gets this.
 */
final class FactoryWorkflowClientPort implements WorkflowClientPort
{
    public function __construct(private readonly RuntimeFactory $factory) {}

    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId
    {
        return $this->factory->workflowClientPort()->start($executionId, $workflowType, $input, $options);
    }

    public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void
    {
        $this->factory->workflowClientPort()->signal($executionId, $name, $arguments, $requestId);
    }

    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        return $this->factory->workflowClientPort()->query($executionId, $name, $arguments);
    }

    public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed
    {
        return $this->factory->workflowClientPort()->update($executionId, $name, $arguments, $updateId);
    }

    public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed
    {
        return $this->factory->workflowClientPort()->result($executionId, $timeout);
    }

    public function cancel(ExecutionId $executionId, ?string $requestId = null): void
    {
        $this->factory->workflowClientPort()->cancel($executionId, $requestId);
    }

    public function terminate(ExecutionId $executionId, string $reason = ''): void
    {
        $this->factory->workflowClientPort()->terminate($executionId, $reason);
    }
}
