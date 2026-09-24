<?php

declare(strict_types=1);

namespace integration\Temporal;

use Google\Protobuf\Duration;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientFactory;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\TerminateWorkflowExecutionRequest;

/**
 * The listing reports a terminated or timed-out run as `Failed`; the `Failed` filter must then
 * return it too, or a dashboard filtered on failures hides runs its unfiltered view marks failed
 * (#504).
 */
final class TemporalRunCatalogFailedFilterTest extends TestCase
{
    use FreshNamespace;

    private const TIMEOUT_SECONDS = 15.0;

    private TemporalConnection $connection;
    private WorkflowServiceClientInterface $client;

    protected function setUp(): void
    {
        $this->connection = self::freshNamespaceConnection();
        $this->client = WorkflowServiceClientFactory::create($this->connection);
    }

    public function testATerminatedRunIsFoundUnderTheFailedFilter(): void
    {
        $this->start('exec-terminated');
        $this->client->TerminateWorkflowExecution(new TerminateWorkflowExecutionRequest([
            'namespace' => $this->connection->namespace->name(),
            'workflow_execution' => new WorkflowExecution(['workflow_id' => 'exec-terminated']),
        ]));

        $this->assertListedAsFailedAndFilteredAsFailed('exec-terminated');
    }

    public function testATimedOutRunIsFoundUnderTheFailedFilter(): void
    {
        $this->start('exec-timed-out', new Duration(['seconds' => 1]));

        $this->assertListedAsFailedAndFilteredAsFailed('exec-timed-out');
    }

    private function start(string $workflowId, ?Duration $executionTimeout = null): void
    {
        $this->client->StartWorkflowExecution(new StartWorkflowExecutionRequest([
            'namespace' => $this->connection->namespace->name(),
            'workflow_id' => $workflowId,
            'workflow_type' => new WorkflowType(['name' => 'App\\OrderWorkflow']),
            'task_queue' => new TaskQueue(['name' => 'failed-filter-' . $workflowId]),
            'request_id' => bin2hex(random_bytes(16)),
            'workflow_execution_timeout' => $executionTimeout,
        ]));
    }

    private function assertListedAsFailedAndFilteredAsFailed(string $workflowId): void
    {
        $catalog = new TemporalWorkflowRunCatalog($this->client, $this->connection);
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        do {
            $listed = self::statusOf($workflowId, $catalog->listRuns());
            if (WorkflowRunStatus::Failed === $listed) {
                break;
            }
            usleep(200_000);
        } while (microtime(true) < $deadline);
        self::assertSame(WorkflowRunStatus::Failed, $listed, \sprintf('"%s" never showed up as failed in the listing', $workflowId));

        self::assertSame(
            WorkflowRunStatus::Failed,
            self::statusOf($workflowId, $catalog->listRuns(WorkflowRunStatus::Failed)),
            'the Failed filter leaves out a run the listing reports as failed',
        );
    }

    private static function statusOf(string $workflowId, \Gplanchat\Durable\Observation\WorkflowRunPage $page): ?WorkflowRunStatus
    {
        foreach ($page->runs as $run) {
            if ($workflowId === $run->groupId) {
                return $run->status;
            }
        }

        return null;
    }
}
