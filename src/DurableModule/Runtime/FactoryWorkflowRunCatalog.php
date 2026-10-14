<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

/**
 * The run catalogue the ObjectManager hands out (`di.xml` prefers it): the one of the backend
 * {@see RuntimeFactory} assembles. A declared repository takes it with the client port.
 */
final class FactoryWorkflowRunCatalog implements WorkflowRunCatalogInterface
{
    public function __construct(private readonly RuntimeFactory $factory) {}

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20, ?WorkflowRunFilter $filter = null): WorkflowRunPage
    {
        return $this->factory->catalog()->listRuns($status, $cursor, $limit, $filter);
    }

    public function canFilterRuns(?WorkflowRunFilter $filter = null): bool
    {
        return $this->factory->catalog()->canFilterRuns($filter);
    }

    public function findRun(ExecutionId $executionId): ?WorkflowRunDescription
    {
        return $this->factory->catalog()->findRun($executionId);
    }

    public function readHistory(WorkflowRunDescription $run): array
    {
        return $this->factory->catalog()->readHistory($run);
    }

    public function checkHealth(): BackendHealth
    {
        return $this->factory->catalog()->checkHealth();
    }
}
