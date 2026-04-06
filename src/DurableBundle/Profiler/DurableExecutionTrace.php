<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Profiler;

use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;

/**
 * Trace processus pour une requête HTTP : envois {@see \Gplanchat\Durable\Transport\WorkflowRunMessage}
 * (middleware Messenger), puis {@see WorkflowExecutionObserverInterface} (runs moteur, activités exécutées).
 *
 * L’historique persistant reste dans l’event store ; cette trace sert au bandeau temporel « cette requête »
 * (worker d’activité inclus) et complète le journal quand tout s’exécute dans le même processus.
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
     * Enregistre un envoi de {@see \Gplanchat\Durable\Transport\WorkflowRunMessage} sur le bus (sans exécuter le workflow dans ce processus si le handler tourne ailleurs).
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
            static fn (array $e): bool => ($e['executionId'] ?? '') === $executionId,
        ));
    }

    public function countDispatchEvents(): int
    {
        return \count(array_filter(
            $this->timeline,
            static fn (array $e): bool => ($e['kind'] ?? '') === 'dispatch',
        ));
    }
}
