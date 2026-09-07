<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Profiler;

use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;

/**
 * Process trace for one HTTP request: {@see \Gplanchat\Durable\Transport\WorkflowRunMessage} dispatches
 * (Messenger middleware), then {@see WorkflowExecutionObserverInterface} (engine runs, executed activities).
 *
 * The persistent history stays in the event store; this trace feeds the "this request" time band
 * (activity worker included) and completes the journal when everything runs in the same process.
 */
final class DurableExecutionTrace implements WorkflowExecutionObserverInterface
{
    private int $seq = 0;

    /** @var list<array<string, mixed>> */
    private array $timeline = [];

    public function reset(): void
    {
        $this->seq = 0;
        $this->timeline = [];
    }

    /**
     * Records a dispatch of {@see \Gplanchat\Durable\Transport\WorkflowRunMessage} on the bus (without executing the workflow in this process if the handler runs elsewhere).
     *
     * @param array<string, mixed> $payload
     */
    public function onWorkflowDispatchRequested(
        string $executionId,
        string $workflowType,
        array $payload,
        bool $isResume,
        ?string $transportNames,
    ): void {
        $this->timeline[] = [
            'seq' => ++$this->seq,
            'at' => microtime(true),
            'kind' => 'dispatch',
            'executionId' => $executionId,
            'workflowType' => $workflowType,
            'payload' => $payload,
            'isResume' => $isResume,
            'transportNames' => $transportNames,
        ];
    }

    #[\Override]
    public function onWorkflowRun(string $executionId, string $workflowType, bool $isResume): void
    {
        $this->timeline[] = [
            'seq' => ++$this->seq,
            'at' => microtime(true),
            'kind' => 'workflow',
            'executionId' => $executionId,
            'workflowType' => $workflowType,
            'isResume' => $isResume,
        ];
    }

    #[\Override]
    public function onActivityExecuted(
        string $executionId,
        string $activityId,
        string $activityName,
        float $durationSeconds,
        bool $success,
        ?string $errorClass,
    ): void {
        $this->timeline[] = [
            'seq' => ++$this->seq,
            'at' => microtime(true),
            'kind' => 'activity',
            'executionId' => $executionId,
            'activityId' => $activityId,
            'activityName' => $activityName,
            'durationSeconds' => $durationSeconds,
            'success' => $success,
            'errorClass' => $errorClass,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTimeline(): array
    {
        return $this->timeline;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTimelineForExecution(string $executionId): array
    {
        return array_values(array_filter(
            $this->timeline,
            static fn(array $e): bool => ($e['executionId'] ?? '') === $executionId,
        ));
    }

    public function countDispatchEvents(): int
    {
        return \count(array_filter(
            $this->timeline,
            static fn(array $e): bool => ($e['kind'] ?? '') === 'dispatch',
        ));
    }
}
