<?php

declare(strict_types=1);

namespace unit\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;

/**
 * Records signals and updates instead of sending them to a cluster.
 *
 * The workflow id differs from the execution id on purpose: a caller that skipped
 * {@see workflowId()} would target a workflow that does not exist.
 */
final class RecordingWorkflowClient implements WorkflowClientInterface
{
    /** @var list<array{string, string, string, array<string, mixed>, string|null}> */
    public array $calls = [];

    /**
     * The next call is recorded, then fails: the cluster accepted it and the answer was lost.
     */
    public bool $loseNextAnswer = false;

    public function startAsync(string $workflowType, array $payload, string $executionId): string
    {
        throw new \LogicException('not expected');
    }

    public function startSync(string $workflowType, array $payload, string $executionId): mixed
    {
        throw new \LogicException('not expected');
    }

    public function pollForCompletion(string $executionId, int $refreshIntervalMs = 500, int $maxRefreshes = 120): mixed
    {
        throw new \LogicException('not expected');
    }

    public function signal(string $workflowId, \BackedEnum|string $signalName, array $args = [], ?string $requestId = null): void
    {
        $this->calls[] = ['signal', $workflowId, $signalName instanceof \BackedEnum ? (string) $signalName->value : $signalName, $args, $requestId];
        $this->answer();
    }

    public function query(string $workflowId, string $queryType, array $args = []): mixed
    {
        throw new \LogicException('not expected');
    }

    public function update(string $workflowId, string $updateName, array $args = [], ?string $updateId = null): mixed
    {
        $this->calls[] = ['update', $workflowId, $updateName, $args, $updateId];
        $this->answer();

        return null;
    }

    public function workflowId(string $executionId): string
    {
        return 'wf-' . $executionId;
    }

    private function answer(): void
    {
        if ($this->loseNextAnswer) {
            $this->loseNextAnswer = false;

            throw new \RuntimeException('DEADLINE_EXCEEDED');
        }
    }
}
