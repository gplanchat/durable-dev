<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Profiler;

use Gplanchat\Durable\Debug\WorkflowDispatchObserverInterface;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;

/**
 * Process trace for one HTTP request: {@see \Gplanchat\Durable\Transport\ResumeWorkflowMessage} dispatches
 * (Messenger middleware), then {@see WorkflowExecutionObserverInterface} (engine runs, executed activities).
 *
 * The persistent history stays in the event store; this trace feeds the "this request" time band
 * (activity worker included) and completes the journal when everything runs in the same process.
 */
final class DurableExecutionTrace implements WorkflowExecutionObserverInterface, WorkflowDispatchObserverInterface
{
    /**
     * The trace keeps the last entries only. `kernel.reset` empties it between two Messenger
     * messages, but never fires on a Temporal worker, whose transports work inside `get()` and
     * always look idle: this bound is the one guard that holds for all three.
     */
    public const MAX_ENTRIES = 2000;

    private int $seq = 0;

    /** @var list<array<string, mixed>> */
    private array $timeline = [];

    public function reset(): void
    {
        $this->seq = 0;
        $this->timeline = [];
    }

    /**
     * Records a dispatch of {@see \Gplanchat\Durable\Transport\ResumeWorkflowMessage} on the bus (without executing the workflow in this process if the handler runs elsewhere).
     *
     * @param array<string, mixed> $payload
     */
    #[\Override]
    public function onWorkflowDispatchRequested(
        string $executionId,
        string $workflowType,
        array $payload,
        bool $isResume,
        ?string $transportNames,
    ): void {
        $this->record([
            'seq' => ++$this->seq,
            'at' => microtime(true),
            'kind' => 'dispatch',
            'executionId' => $executionId,
            'workflowType' => $workflowType,
            'payload' => $payload,
            'isResume' => $isResume,
            'transportNames' => $transportNames,
        ]);
    }

    #[\Override]
    public function onWorkflowRun(string $executionId, string $workflowType, bool $isResume): void
    {
        $this->record([
            'seq' => ++$this->seq,
            'at' => microtime(true),
            'kind' => 'workflow',
            'executionId' => $executionId,
            'workflowType' => $workflowType,
            'isResume' => $isResume,
        ]);
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
        $this->record([
            'seq' => ++$this->seq,
            'at' => microtime(true),
            'kind' => 'activity',
            'executionId' => $executionId,
            'activityId' => $activityId,
            'activityName' => $activityName,
            'durationSeconds' => $durationSeconds,
            'success' => $success,
            'errorClass' => $errorClass,
        ]);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function record(array $entry): void
    {
        $this->timeline[] = $entry;
        if (\count($this->timeline) > self::MAX_ENTRIES) {
            // ponytail: array_shift re-indexes the 2 000 entries on each append past the bound;
            // a head index into a fixed array if a profile ever shows it.
            array_shift($this->timeline);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTimeline(): array
    {
        return $this->timeline;
    }

    public function countDispatchEvents(): int
    {
        return \count(array_filter(
            $this->timeline,
            static fn(array $e): bool => ($e['kind'] ?? '') === 'dispatch',
        ));
    }
}
