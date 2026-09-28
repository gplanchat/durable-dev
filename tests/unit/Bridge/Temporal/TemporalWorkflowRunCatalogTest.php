<?php

declare(strict_types=1);

namespace unit\Gplanchat\Bridge\Temporal;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\DurableSearchAttributes;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\Store\TemporalWorkflowRunCatalog;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Exception\RunFilterUnavailableException;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Common\V1\Payload;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionResponse;
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

    /**
     * A memo another client wrote is not ours to trust: one that does not decode names nothing, and
     * must not take the rest of the page down with it.
     */
    public function testAMemoThatDoesNotDecodeFallsBackToTheWorkflowId(): void
    {
        $info = $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200);
        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID] = new Payload(['data' => '{not json']);
        $info->setMemo($memo);

        self::assertSame('wf-1', $this->catalog($this->responseWith($info))->listRuns()->runs[0]->executionId);
    }

    /**
     * #514: on Temporal the worker writes what a suspended run waits on in the `durableWaitingOn`
     * memo; the run list reads it back, as it reads the SQL column.
     */
    public function testARunningRunTellsWhatItWaitsOnFromTheMemo(): void
    {
        $info = $this->waitingOn($this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200), 'activity charge');

        self::assertSame('activity charge', $this->catalog($this->responseWith($info))->listRuns()->runs[0]->waitingOn);
    }

    /**
     * The worker upserts the wait beside the execution id, and the server merges memo keys: a run
     * keeps both.
     */
    public function testTheWaitAndTheExecutionIdAreReadFromTheSameMemo(): void
    {
        $info = $this->withExecutionId($this->info('durable-order-42', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200), 'order/42');
        $info->getMemo()?->getFields()->offsetSet(JournalExecutionIdResolver::MEMO_KEY_DURABLE_WAITING_ON, JsonPlainPayload::encode('activity charge'));

        $run = $this->catalog($this->responseWith($info))->listRuns()->runs[0];

        self::assertSame('order/42', $run->executionId);
        self::assertSame('activity charge', $run->waitingOn);
    }

    public function testAnEndedRunWaitsForNothingWhateverTheMemoStillSays(): void
    {
        $info = $this->waitingOn($this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_COMPLETED, 1_700_000_200), 'activity charge');

        self::assertNull($this->catalog($this->responseWith($info))->listRuns()->runs[0]->waitingOn, 'the memo outlives the run');
    }

    public function testAWaitThatDoesNotDecodeIsAbsent(): void
    {
        $info = $this->info('wf-1', 'run-1', 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200);
        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_WAITING_ON] = new Payload(['data' => '{not json']);
        $info->setMemo($memo);

        self::assertNull($this->catalog($this->responseWith($info))->listRuns()->runs[0]->waitingOn);
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
        $asked = [];
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('ListWorkflowExecutions')->willReturnCallback(static function (ListWorkflowExecutionsRequest $request) use (&$asked, $response): ListWorkflowExecutionsResponse {
            $asked[] = $request->getNextPageToken();

            return $response;
        });
        $catalog = new TemporalWorkflowRunCatalog($client, $this->connection());

        // The cursor is opaque (#383 wraps the token with the way back): what counts is that the
        // next page asks the server for exactly the token it gave.
        $catalog->listRuns(null, $catalog->listRuns()->nextCursor);

        self::assertSame(['', 'server-token'], $asked);
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

    /**
     * #514: one `DescribeWorkflowExecution` on the workflow id Durable derives from the execution id,
     * with no run id, so the current run of the chain. No visibility query: the id is not a string
     * that goes into one.
     */
    public function testARunIsFoundByItsExecutionIdThroughItsWorkflowId(): void
    {
        $requests = [];
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->expects(self::never())->method('ListWorkflowExecutions');
        $client->method('DescribeWorkflowExecution')->willReturnCallback(function (DescribeWorkflowExecutionRequest $request) use (&$requests): DescribeWorkflowExecutionResponse {
            $requests[] = $request;

            return new DescribeWorkflowExecutionResponse(['workflow_execution_info' => $this->withExecutionId(
                $this->info('durable-order-42', self::RUN_ID, 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
                'order/42',
            )]);
        });

        $run = (new TemporalWorkflowRunCatalog($client, $this->connection()))->findRun('order/42');

        self::assertSame('order/42', $run?->executionId);
        self::assertSame(self::RUN_ID, $run->runId);
        self::assertSame(WorkflowClient::workflowIdOf('order/42'), $requests[0]->getExecution()?->getWorkflowId());
        self::assertSame('', $requests[0]->getExecution()->getRunId(), 'the current run of the chain');
    }

    /**
     * #566: a run started before the injective mapping lives under the lossy workflow id. It is
     * still found, for one release, when its memo names the execution asked for, and only then.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function legacyRuns(): iterable
    {
        yield 'started by this execution' => ['order/42', true];
        yield 'started by another execution with the same legacy id' => ['order 42', false];
    }

    #[DataProvider('legacyRuns')]
    public function testARunStartedUnderTheLegacyWorkflowIdIsFoundOnlyByItsOwnExecution(string $startedBy, bool $found): void
    {
        $asked = [];
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('DescribeWorkflowExecution')->willReturnCallback(function (DescribeWorkflowExecutionRequest $request) use (&$asked, $startedBy): DescribeWorkflowExecutionResponse {
            $asked[] = $request->getExecution()?->getWorkflowId();
            if ('durable-order-42' !== $request->getExecution()?->getWorkflowId()) {
                throw new \RuntimeException('workflow not found', 5);
            }

            return new DescribeWorkflowExecutionResponse(['workflow_execution_info' => $this->withExecutionId(
                $this->info('durable-order-42', self::RUN_ID, 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200),
                $startedBy,
            )]);
        });

        $run = (new TemporalWorkflowRunCatalog($client, $this->connection()))->findRun('order/42');

        self::assertSame($found ? 'order/42' : null, $run?->executionId);
        self::assertSame([WorkflowClient::workflowIdOf('order/42'), 'durable-order-42'], \array_slice($asked, 0, 2), 'the new id first, the legacy one next');
    }

    /**
     * The workflow id is a lossy derivation: `order/42` and `order-42` both become
     * `durable-order-42`. A run whose memo names another execution is not the one asked for.
     */
    public function testARunWhoseMemoNamesAnotherExecutionIsNotFound(): void
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('DescribeWorkflowExecution')->willReturn(new DescribeWorkflowExecutionResponse(['workflow_execution_info' => $this->withExecutionId(
            $this->info('durable-order-42', self::RUN_ID, 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_COMPLETED, 1_700_000_200),
            'order/42',
        )]));

        self::assertNull((new TemporalWorkflowRunCatalog($client, $this->connection()))->findRun('order-42'));
    }

    /**
     * A run Durable did not start is listed under its own workflow id, which has no memo to read:
     * its page must find it by that id too, or the list links to a not-found.
     */
    public function testARunDurableDidNotStartIsFoundByItsOwnWorkflowId(): void
    {
        $asked = [];
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('DescribeWorkflowExecution')->willReturnCallback(function (DescribeWorkflowExecutionRequest $request) use (&$asked): DescribeWorkflowExecutionResponse {
            $asked[] = $request->getExecution()?->getWorkflowId();
            if ('legacy-7' !== $request->getExecution()?->getWorkflowId()) {
                throw new \RuntimeException('workflow not found', 5);
            }

            return new DescribeWorkflowExecutionResponse(['workflow_execution_info' => $this->info('legacy-7', self::RUN_ID, 'App\\OrderWorkflow', 'orders', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 1_700_000_200)]);
        });

        $run = (new TemporalWorkflowRunCatalog($client, $this->connection()))->findRun('legacy-7');

        self::assertSame('legacy-7', $run?->executionId);
        self::assertSame(['durable-legacy-7', 'legacy-7'], $asked, 'Durable\'s own workflow id first');
    }

    public function testAnExecutionTheServerDoesNotKnowIsNotFound(): void
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('DescribeWorkflowExecution')->willThrowException(new \RuntimeException('workflow not found', 5));

        self::assertNull((new TemporalWorkflowRunCatalog($client, $this->connection()))->findRun('order/nobody'));
    }

    public function testAnotherFailureIsNotPassedOffAsAnUnknownRun(): void
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('DescribeWorkflowExecution')->willThrowException(new \RuntimeException('unavailable', 14));

        $this->expectExceptionCode(14);

        (new TemporalWorkflowRunCatalog($client, $this->connection()))->findRun('order/42');
    }

    /**
     * #558: the filters read Durable's search attributes, spelled by the function that wrote them.
     * They come from outside, so a quote cannot end the literal; the name loses its backslashes as
     * the writer did, and the prefix is normalized but never hashed, so it stays a prefix. The status
     * clause is parenthesised, since it may be a `NOT IN`.
     */
    public function testTheFiltersQueryDurablesSearchAttributes(): void
    {
        $queries = [];
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('ListWorkflowExecutions')->willReturnCallback(static function (ListWorkflowExecutionsRequest $request) use (&$queries): ListWorkflowExecutionsResponse {
            $queries[] = $request->getQuery();

            return new ListWorkflowExecutionsResponse();
        });
        $catalog = new TemporalWorkflowRunCatalog($client, new TemporalConnection('localhost:7233', 'durable-test', searchAttributes: true));

        $catalog->listRuns(filter: new WorkflowRunFilter(workflowName: "App\\Order'Workflow"));
        $catalog->listRuns(WorkflowRunStatus::Running, filter: new WorkflowRunFilter('App\\OrderWorkflow', 'ord.'));
        $catalog->listRuns(filter: new WorkflowRunFilter(executionIdPrefix: str_repeat('a', 300)));

        self::assertSame("DurableWorkflowName = 'App.Order\\'Workflow'", $queries[0]);
        self::assertSame('(' . $this->filterQuery(WorkflowRunStatus::Running) . ") AND (DurableWorkflowName = 'App.OrderWorkflow') AND (DurableExecutionId STARTS_WITH 'ord%2E')", $queries[1]);
        self::assertSame("DurableExecutionId STARTS_WITH '" . str_repeat('a', 300) . "'", $queries[2], 'a prefix is never hashed');
    }

    /**
     * #557: a visibility store on SQLite (the dev server's) matches STARTS_WITH without case, one on
     * PostgreSQL with it. The catalog keeps only the runs whose attribute does start with the
     * prefix, so case counts whatever the store.
     */
    public function testAPrefixKeepsCaseWhateverTheVisibilityStore(): void
    {
        $response = new ListWorkflowExecutionsResponse();
        $response->setExecutions([
            self::carrying($this->info('durable-ord-1', '11111111-1111-1111-1111-111111111111', 'App\\Order', 'q', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 20), 'ord-1'),
            self::carrying($this->info('durable-ORD-2', '22222222-2222-2222-2222-222222222222', 'App\\Order', 'q', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 10), 'ORD-2'),
        ]);
        $catalog = new TemporalWorkflowRunCatalog($this->client($response), new TemporalConnection('localhost:7233', 'durable-test', searchAttributes: true));

        $runs = $catalog->listRuns(filter: new WorkflowRunFilter(executionIdPrefix: 'ord'))->runs;

        self::assertSame(['11111111-1111-1111-1111-111111111111'], array_map(static fn($run): string => $run->runId, $runs));
    }

    /**
     * #557: the runs dropped for their case are asked for again, only as many as are missing, and
     * at most five times: past that the page comes back short, with a cursor to go on.
     */
    public function testAPrefixedPageAsksAgainForWhatItDroppedAFewTimesAtMost(): void
    {
        $sizes = [];
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->method('ListWorkflowExecutions')->willReturnCallback(function (ListWorkflowExecutionsRequest $request) use (&$sizes): ListWorkflowExecutionsResponse {
            $sizes[] = $request->getPageSize();
            $response = new ListWorkflowExecutionsResponse();
            $response->setExecutions([self::carrying($this->info('durable-ORD', bin2hex(random_bytes(4)) . '-1111-1111-1111-111111111111', 'App\\Order', 'q', WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING, 10), 'ORD')]);
            $response->setNextPageToken('more');

            return $response;
        });
        $catalog = new TemporalWorkflowRunCatalog($client, new TemporalConnection('localhost:7233', 'durable-test', searchAttributes: true));

        $page = $catalog->listRuns(limit: 3, filter: new WorkflowRunFilter(executionIdPrefix: 'ord'));

        self::assertSame([3, 3, 3, 3, 3], $sizes);
        self::assertSame([], $page->runs);
        self::assertNotNull($page->nextCursor, 'a short page still says there is more');
    }

    /**
     * #558: with the switch off, no run carries the attributes the filters read. Answering an
     * unfiltered page, or an empty one, would both be lies: the catalog says it cannot filter, and
     * refuses a filter before calling the server.
     */
    public function testWithoutSearchAttributesTheCatalogCannotFilterAndSaysSo(): void
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->expects($this->never())->method('ListWorkflowExecutions');
        $catalog = new TemporalWorkflowRunCatalog($client, $this->connection());

        self::assertFalse($catalog->canFilterRuns());
        self::assertTrue((new TemporalWorkflowRunCatalog($client, new TemporalConnection('localhost:7233', 'durable-test', searchAttributes: true)))->canFilterRuns());

        $this->expectException(RunFilterUnavailableException::class);
        $this->expectExceptionMessage('durable.temporal.search_attributes');

        $catalog->listRuns(filter: new WorkflowRunFilter(executionIdPrefix: 'ord'));
    }

    public function testAnEmptyFilterIsNoFilterEvenWithoutSearchAttributes(): void
    {
        $client = $this->createMock(WorkflowServiceClientInterface::class);
        $client->expects($this->once())->method('ListWorkflowExecutions')->willReturn(new ListWorkflowExecutionsResponse());

        (new TemporalWorkflowRunCatalog($client, $this->connection()))->listRuns(filter: new WorkflowRunFilter('', ''));
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

    private static function carrying(WorkflowExecutionInfo $info, string $executionId): WorkflowExecutionInfo
    {
        $attributes = new \Temporal\Api\Common\V1\SearchAttributes();
        $fields = $attributes->getIndexedFields();
        $fields[DurableSearchAttributes::EXECUTION_ID] = JsonPlainPayload::encode(DurableSearchAttributes::value($executionId));
        $info->setSearchAttributes($attributes);

        return $info;
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

    private function withExecutionId(WorkflowExecutionInfo $info, string $executionId): WorkflowExecutionInfo
    {
        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID] = JsonPlainPayload::encode($executionId);
        $info->setMemo($memo);

        return $info;
    }

    private function waitingOn(WorkflowExecutionInfo $info, string $waitingOn): WorkflowExecutionInfo
    {
        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_WAITING_ON] = JsonPlainPayload::encode($waitingOn);
        $info->setMemo($memo);

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
