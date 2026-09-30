<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Tests;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\NexusOperationSummary;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\NexusOperationCatalogInterface;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

/**
 * A catalog the test fills by hand, and which remembers what the page asked it.
 */
final class FakeCatalog implements WorkflowRunCatalogInterface, NexusOperationCatalogInterface
{
    /** @var list<NexusOperationSummary> what every run is waiting on in Nexus */
    public array $nexusOperations = [];

    public ?string $askedCursor = null;
    public ?WorkflowRunFilter $askedFilter = null;

    /**
     * @param list<WorkflowRunDescription>             $runs
     * @param array<string, list<WorkflowRunEvent>>    $histories by execution id
     */
    public function __construct(
        public array $runs = [],
        public ?string $nextCursor = null,
        public bool $reachable = true,
        public bool $filterable = true,
        public array $histories = [],
    ) {}

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20, ?WorkflowRunFilter $filter = null): WorkflowRunPage
    {
        $this->askedCursor = $cursor;
        $this->askedFilter = $filter;

        return new WorkflowRunPage($this->runs, $this->nextCursor);
    }

    public function canFilterRuns(?WorkflowRunFilter $filter = null): bool
    {
        return $this->filterable;
    }

    public function findRun(ExecutionId $executionId): ?WorkflowRunDescription
    {
        foreach ($this->runs as $run) {
            if ($run->executionId === (string) $executionId) {
                return $run;
            }
        }

        return null;
    }

    public function readHistory(WorkflowRunDescription $run): array
    {
        return $this->histories[$run->executionId] ?? [];
    }

    public function readNexusOperations(WorkflowRunDescription $run): array
    {
        return $this->nexusOperations;
    }

    public function checkHealth(): BackendHealth
    {
        return new BackendHealth('fake', $this->reachable, $this->reachable ? 'The fake answers.' : 'The fake is down.', new \DateTimeImmutable('2026-09-29 10:00:00'));
    }
}
