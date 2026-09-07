<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Grpc\UnaryCall;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * What the Temporal catalog says of a visibility response.
 *
 * This file was a **parity** test: it read the same server response with the plugin's provider and
 * with this catalog, to prove that moving the code behind the port changed nothing — except where
 * the port knows how to say it better. The provider having joined the bridge and then disappeared,
 * the comparison has no second term any more, and only the adapter's contract is left.
 *
 * What remains is worth recalling: the provider filed **everything** that was neither running nor
 * completed under "failed". A cancelled execution, or one moved to continue-as-new, therefore
 * showed as a failure, and a long workflow turned red at every roll-over. The two tests that still
 * carry `IsNoLongerReportedAsFailed` guard that correction.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/tasks.md §2.9 §5.1
 */
#[RequiresPhpExtension('grpc')]
final class TemporalWorkflowRunCatalogTest extends TestCase
{
    public function testRunsComeBackNamedAndInStartOrder(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
            $this->info('wf-2', 'run-2', 'App\\ReportWorkflow', 'reports', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_COMPLETED, 1_700_000_100),
        );

        $page = $this->catalog($response)->listRuns();

        self::assertSame(['run-1', 'run-2'], array_map(static fn($run): string => $run->runId, $page->runs));
        self::assertSame(
            ['App\\OrderWorkflow', 'App\\ReportWorkflow'],
            array_map(static fn($run): string => $run->workflowName, $page->runs),
        );
    }

    public function testTheWorkflowIdSurvivesAsTheGroupingIdentifier(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
        );

        self::assertSame('wf-1', $this->catalog($response)->listRuns()->runs[0]->groupId);
    }

    /**
     * The deliberate divergence: what the port knows how to say and the provider did not.
     */
    public function testACancelledRunIsNoLongerReportedAsFailed(): void
    {
        $response = $this->responseWith(
            $this->info('wf-3', 'run-3', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CANCELED, 1_700_000_300),
        );

        self::assertSame(WorkflowRunStatus::Cancelled, $this->catalog($response)->listRuns()->runs[0]->status);
    }

    public function testAContinuedAsNewRunIsNoLongerReportedAsFailed(): void
    {
        $response = $this->responseWith(
            $this->info('wf-4', 'run-4', 'App\\ReportWorkflow', 'reports', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CONTINUED_AS_NEW, 1_700_000_400),
        );

        self::assertSame(WorkflowRunStatus::ContinuedAsNew, $this->catalog($response)->listRuns()->runs[0]->status);
    }

    public function testARealFailureIsAFailure(): void
    {
        $response = $this->responseWith(
            $this->info('wf-5', 'run-5', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_FAILED, 1_700_000_500),
        );

        self::assertSame(WorkflowRunStatus::Failed, $this->catalog($response)->listRuns()->runs[0]->status);
    }

    public function testTheServerPageTokenBecomesTheCursor(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
        );
        $response->setNextPageToken('jeton-serveur');

        self::assertSame(base64_encode('jeton-serveur'), $this->catalog($response)->listRuns()->nextCursor);
    }

    private function info(string $workflowId, string $runId, string $type, string $taskQueue, int $status, int $startedAt): WorkflowExecutionInfo
    {
        $execution = new WorkflowExecution();
        $execution->setWorkflowId($workflowId);
        $execution->setRunId($runId);

        $workflowType = new WorkflowType();
        $workflowType->setName($type);

        $start = new Timestamp();
        $start->setSeconds($startedAt);

        $info = new WorkflowExecutionInfo();
        $info->setExecution($execution);
        $info->setType($workflowType);
        $info->setTaskQueue($taskQueue);
        $info->setStatus($status);
        $info->setStartTime($start);

        return $info;
    }

    private function responseWith(WorkflowExecutionInfo ...$infos): ListWorkflowExecutionsResponse
    {
        $response = new ListWorkflowExecutionsResponse();
        $response->setExecutions($infos);

        return $response;
    }

    private function catalog(ListWorkflowExecutionsResponse $response): TemporalWorkflowRunCatalog
    {
        return new TemporalWorkflowRunCatalog($this->client($response), $this->connection());
    }

    private function connection(): TemporalConnection
    {
        return new TemporalConnection('localhost:7233', 'durable-test');
    }

    private function client(ListWorkflowExecutionsResponse $response): WorkflowServiceClient
    {
        $status = new \stdClass();
        $status->code = \Grpc\STATUS_OK;
        $status->details = '';

        $call = $this->createMock(UnaryCall::class);
        $call->method('wait')->willReturn([$response, $status]);

        $client = $this->createMock(WorkflowServiceClient::class);
        $client->method('ListWorkflowExecutions')->willReturn($call);

        return $client;
    }
}
