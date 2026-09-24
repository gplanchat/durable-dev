<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;

final class WorkflowClientDouble implements WorkflowClientInterface
{
    public function startAsync(string $workflowType, array $payload, string $executionId): string
    {
        return $executionId;
    }

    public function startSync(string $workflowType, array $payload, string $executionId): mixed
    {
        return null;
    }

    public function pollForCompletion(string $executionId, int $refreshIntervalMs = 500, int $maxRefreshes = 120): mixed
    {
        return null;
    }

    public function signal(string $workflowId, \BackedEnum|string $signalName, array $args = []): void
    {
    }

    public function query(string $workflowId, string $queryType, array $args = []): mixed
    {
        return null;
    }

    public function update(string $workflowId, string $updateName, array $args = []): mixed
    {
        return null;
    }

    public function workflowId(string $executionId): string
    {
        return $executionId;
    }
}

?>
-----
<?php

namespace unit\DurableRector\Fixture\DurableUpgrade;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;

final class WorkflowClientDouble implements WorkflowClientInterface
{
    public function startAsync(string $workflowType, array $payload, string $executionId): string
    {
        return $executionId;
    }

    public function startSync(string $workflowType, array $payload, string $executionId): mixed
    {
        return null;
    }

    public function pollForCompletion(string $executionId, int $refreshIntervalMs = 500, int $maxRefreshes = 120): mixed
    {
        return null;
    }

    public function signal(string $workflowId, \BackedEnum|string $signalName, array $args = [], ?string $requestId = null): void
    {
    }

    public function query(string $workflowId, string $queryType, array $args = []): mixed
    {
        return null;
    }

    public function update(string $workflowId, string $updateName, array $args = [], ?string $updateId = null): mixed
    {
        return null;
    }

    public function workflowId(string $executionId): string
    {
        return $executionId;
    }
}

?>
