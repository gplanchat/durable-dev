<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Profiler;

use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;

/**
 * Journal chronologique des runs workflow et des exécutions d’activités (même requête HTTP / processus).
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
            static fn (array $e): bool => ($e['executionId'] ?? '') === $executionId,
        ));
    }

    public function countWorkflowEvents(): int
    {
        return \count(array_filter(
            $this->timeline,
            static fn (array $e): bool => ($e['kind'] ?? '') === 'workflow',
        ));
    }

    public function countActivityEvents(): int
    {
        return \count(array_filter(
            $this->timeline,
            static fn (array $e): bool => ($e['kind'] ?? '') === 'activity',
        ));
    }
}
