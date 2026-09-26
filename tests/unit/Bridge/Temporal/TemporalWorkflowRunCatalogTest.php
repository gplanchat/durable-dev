<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsRequest;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsResponse;

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
final class TemporalWorkflowRunCatalogTest extends TestCase
{
    private const RUN_ID = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';

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

    /**
     * #514: the workflow id is `durable-` and a sanitised form of the execution id, which cannot be
     * read back; the execution id travels in the `durableExecutionId` memo.
     */
    public function testTheExecutionIdIsReadFromTheMemo(): void
    {
        $info = $this->info('durable-order-42', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200);
        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID] = JsonPlainPayload::encode('order/42');
        $info->setMemo($memo);

        $run = $this->catalog($this->responseWith($info))->listRuns()->runs[0];

        self::assertSame('order/42', $run->executionId);
        self::assertSame('run-1', $run->runId, 'the run id stays the server\'s own');
    }

    public function testARunStartedWithoutTheMemoIsNamedByItsWorkflowId(): void
    {
        $run = $this->catalog($this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
        ))->listRuns()->runs[0];

        self::assertSame('wf-1', $run->executionId, 'not started by Durable: the workflow id is the only name it has');
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

    /**
     * A paused run has not ended and resumes when an operator unpauses it: it is still liable to
     * make progress, which is what Running says (#506).
     */
    public function testAPausedRunIsRunningNotFailed(): void
    {
        $response = $this->responseWith(
            $this->info('wf-6', 'run-6', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_PAUSED, 1_700_000_600),
        );

        self::assertSame(WorkflowRunStatus::Running, $this->catalog($response)->listRuns()->runs[0]->status);
    }

    /**
     * A status the server did not send says nothing, so the close time decides: no close time is a
     * run not known to have ended, a close time is an end this catalog cannot name (#506).
     */
    public function testAnUnspecifiedStatusIsDecidedByTheCloseTime(): void
    {
        $open = $this->info('wf-7', 'run-7', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_UNSPECIFIED, 1_700_000_700);
        $closed = $this->info('wf-8', 'run-8', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_UNSPECIFIED, 1_700_000_800);
        $closeTime = new Timestamp();
        $closeTime->setSeconds(1_700_000_900);
        $closed->setCloseTime($closeTime);

        $runs = $this->catalog($this->responseWith($open, $closed))->listRuns()->runs;
        $byId = array_column(array_map(static fn($run): array => [$run->runId, $run->status], $runs), 1, 0);

        self::assertSame(WorkflowRunStatus::Running, $byId['run-7'], 'no close time: not known to have ended');
        self::assertSame(WorkflowRunStatus::Failed, $byId['run-8'], 'a close time: an end the catalog cannot name');
    }

    public function testTheServerPageTokenBecomesTheCursor(): void
    {
        $response = $this->responseWith(
            $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
        );
        $response->setNextPageToken('server-token');

        self::assertSame(base64_encode('server-token'), $this->catalog($response)->listRuns()->nextCursor);
    }

    /**
     * A run listed under a status comes back under that status's filter, whatever the server status
     * behind it (#504). The visibility name is derived from the enum constant here, independently of
     * the catalog, so a status the server gains later fails this test until the catalog files it.
     */
    public function testEveryServerStatusIsFoundUnderTheFilterOfTheStatusItIsListedAs(): void
    {
        foreach ((new \ReflectionClass(WorkflowExecutionStatus::class))->getConstants() as $constant => $serverStatus) {
            if (WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_UNSPECIFIED === $serverStatus) {
                continue; // no run is ever stored without a status: there is nothing to find
            }
            $listedAs = $this->catalog($this->responseWith(
                $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', $serverStatus, 1_700_000_000),
            ))->listRuns()->runs[0]->status;

            $query = $this->filterQuery($listedAs);
            $visibilityName = str_replace('_', '', ucwords(strtolower(substr($constant, \strlen('WORKFLOW_EXECUTION_STATUS_'))), '_'));
            $named = str_contains($query, \sprintf('"%s"', $visibilityName));

            self::assertTrue(
                str_contains($query, 'NOT IN') ? !$named : $named,
                \sprintf('%s is listed as %s, but the %s filter leaves it out: %s', $constant, $listedAs->name, $listedAs->name, $query),
            );
        }
    }

    /**
     * Temporal 1.25 rejects a status name it does not know ("invalid ExecutionStatus value 'Paused'"),
     * and CI's servers are all newer: no filter may name `Paused`, the Running one excludes the ends
     * instead (#506).
     */
    public function testNoFilterNamesAStatusOlderServersReject(): void
    {
        foreach (WorkflowRunStatus::cases() as $status) {
            self::assertStringNotContainsString('"Paused"', $this->filterQuery($status), $status->name . ' filter');
        }
    }

    public function testARunIsFoundByItsRunIdInOneVisibilityQuery(): void
    {
        $requests = [];
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('ListWorkflowExecutions')->willReturnCallback(function (ListWorkflowExecutionsRequest $request) use (&$requests): ListWorkflowExecutionsResponse {
            $requests[] = $request;

            return $this->responseWith($this->info('wf-1', self::RUN_ID, 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200));
        });

        $run = (new TemporalWorkflowRunCatalog($client, $this->connection()))->findRun(self::RUN_ID);

        self::assertSame(self::RUN_ID, $run?->runId);
        self::assertSame('wf-1', $run->groupId);
        self::assertCount(1, $requests);
        self::assertSame(\sprintf('RunId = "%s"', self::RUN_ID), $requests[0]->getQuery());
        self::assertSame(1, $requests[0]->getPageSize());
    }

    public function testNoRunIsFoundWhenTheServerListsNone(): void
    {
        self::assertNull($this->catalog($this->responseWith())->findRun(self::RUN_ID));
    }

    /**
     * The id comes from a URL. Temporal's run ids are UUIDs, so anything else is an unknown run and
     * never reaches the visibility query.
     */
    public function testAnIdThatIsNotARunIdIsNotSentToTheServer(): void
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->expects(self::never())->method('ListWorkflowExecutions');

        self::assertNull((new TemporalWorkflowRunCatalog($client, $this->connection()))->findRun('x" OR RunId != "'));
    }

    private function filterQuery(WorkflowRunStatus $status): string
    {
        $queries = [];
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('ListWorkflowExecutions')->willReturnCallback(static function (ListWorkflowExecutionsRequest $request) use (&$queries): ListWorkflowExecutionsResponse {
            $queries[] = $request->getQuery();

            return new ListWorkflowExecutionsResponse();
        });
        (new TemporalWorkflowRunCatalog($client, $this->connection()))->listRuns($status);

        return $queries[0];
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

    private function client(ListWorkflowExecutionsResponse $response): WorkflowServiceClientInterface
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('ListWorkflowExecutions')->willReturn($response);

        return $client;
    }
}
