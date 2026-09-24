<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Store;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\ListWorkflowExecutionsRequest;

/**
 * The catalog of executions as Temporal sees it, in the vocabulary of the component.
 *
 * Temporal keeps the workflow id across continuations and gives each execution its own run id:
 * the run id is the identity, the workflow id the grouping. The DBAL backend does not have this
 * second notion and leaves `groupId` absent — that is a fact one backend has and the other does
 * not, not a shortcoming.
 *
 * The cursor carries the server page token as is, encoded to survive a URL.
 *
 * @see DUR006
 */
final class TemporalWorkflowRunCatalog implements WorkflowRunCatalogInterface
{
    private const BACKEND = 'Temporal';

    public function __construct(
        private readonly WorkflowServiceClientInterface $client,
        private readonly TemporalConnection $connection,
        private readonly ?TemporalHistoryCursor $historyCursor = null,
    ) {}

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        $request = new ListWorkflowExecutionsRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setPageSize(max(1, $limit));
        if (null !== $status) {
            $request->setQuery(self::visibilityQuery($status));
        }
        if (null !== $cursor && '' !== $cursor) {
            $request->setNextPageToken(self::decodeCursor($cursor));
        }

        $response = $this->client->ListWorkflowExecutions(
            $request,
            [],
            ['timeout' => TemporalGrpcTimeouts::SHORT_US],
        );

        $runs = [];
        foreach ($response->getExecutions() as $info) {
            $run = self::describe($info);
            if (null !== $run) {
                $runs[] = $run;
            }
        }

        // The server already orders by descending start date, but the response of a custom
        // visibility query does not guarantee it: we re-sort, as the view used to do.
        usort(
            $runs,
            static fn(WorkflowRunDescription $left, WorkflowRunDescription $right): int => ($right->startedAt?->getTimestamp() ?? 0) <=> ($left->startedAt?->getTimestamp() ?? 0),
        );

        $token = (string) $response->getNextPageToken();

        return new WorkflowRunPage($runs, '' === $token ? null : base64_encode($token));
    }

    /**
     * Without a wired cursor or without a grouping id, there is nothing to ask the server:
     * Temporal requires the workflow id to retrieve a history. An empty list says "I have
     * nothing to show", which is exact, where an exception would say "something is wrong".
     *
     * @return list<WorkflowRunEvent>
     */
    public function readHistory(WorkflowRunDescription $run): array
    {
        $workflowId = $run->groupId ?? '';
        if (null === $this->historyCursor || '' === $workflowId || '' === $run->runId) {
            return [];
        }

        return (new TemporalRunHistoryReader($this->historyCursor))->read($workflowId, $run->runId);
    }

    public function checkHealth(): BackendHealth
    {
        $checkedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        try {
            // A one-row page: the probe borrows the same call as the dashboard, so it also fails
            // when the server answers but the namespace does not exist — which is exactly what
            // the operator needs to know.
            $request = new ListWorkflowExecutionsRequest();
            $request->setNamespace($this->connection->namespace->name());
            $request->setPageSize(1);

            $this->client->ListWorkflowExecutions(
                $request,
                [],
                ['timeout' => TemporalGrpcTimeouts::SHORT_US],
            );
        } catch (\Throwable $failure) {
            return new BackendHealth(
                self::BACKEND,
                false,
                \sprintf('Temporal namespace "%s" is unreachable: %s', $this->connection->namespace->name(), $failure->getMessage()),
                $checkedAt,
            );
        }

        return new BackendHealth(
            self::BACKEND,
            true,
            \sprintf('Connected to Temporal namespace "%s".', $this->connection->namespace->name()),
            $checkedAt,
        );
    }

    private static function describe(WorkflowExecutionInfo $info): ?WorkflowRunDescription
    {
        $execution = $info->getExecution();
        if (null === $execution) {
            return null;
        }

        $runId = (string) $execution->getRunId();
        if ('' === $runId) {
            return null;
        }

        $type = $info->getType();
        $workflowId = (string) $execution->getWorkflowId();

        return new WorkflowRunDescription(
            runId: $runId,
            workflowName: null !== $type ? (string) $type->getName() : 'UnknownWorkflow',
            status: self::statusOf($info),
            startedAt: self::toDateTime($info->getStartTime()),
            endedAt: self::toDateTime($info->getCloseTime()),
            groupId: '' === $workflowId ? null : $workflowId,
        );
    }

    /**
     * Every server status: its name in a visibility query, and the port status it is listed as. The
     * listing and the filter both read this table, so a run listed under a status is found under
     * that status's filter (#504).
     *
     * The provider this catalog replaces filed every abnormal end under "failure": cancelled,
     * terminated, expired and continue-as-new all displayed identically. The port has the
     * vocabulary to tell them apart, and a cancelled execution is not an incident.
     *
     * `TERMINATED` and `TIMED_OUT` stay failures for want of dedicated cases: they are indeed ends
     * that are suffered, and inventing two more brings nothing as long as no view separates them.
     * `PAUSED` is `Running`: the run has not ended and resumes when an operator unpauses it, which is
     * what `Running` says, "still liable to make progress" (#506).
     *
     * @var array<int, array{string, WorkflowRunStatus}>
     */
    private const STATUSES = [
        WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_RUNNING => ['Running', WorkflowRunStatus::Running],
        WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_COMPLETED => ['Completed', WorkflowRunStatus::Completed],
        WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_FAILED => ['Failed', WorkflowRunStatus::Failed],
        WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CANCELED => ['Canceled', WorkflowRunStatus::Cancelled],
        WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_TERMINATED => ['Terminated', WorkflowRunStatus::Failed],
        WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_CONTINUED_AS_NEW => ['ContinuedAsNew', WorkflowRunStatus::ContinuedAsNew],
        WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_TIMED_OUT => ['TimedOut', WorkflowRunStatus::Failed],
        WorkflowExecutionStatus::WORKFLOW_EXECUTION_STATUS_PAUSED => ['Paused', WorkflowRunStatus::Running],
    ];

    /**
     * A status the server did not send (`UNSPECIFIED`), or one this table does not know, says
     * nothing, so the close time decides: none is a run not known to have ended, one is an end this
     * catalog cannot name, filed with the other unnamed ends (#506).
     */
    private static function statusOf(WorkflowExecutionInfo $info): WorkflowRunStatus
    {
        return self::STATUSES[$info->getStatus()][1]
            ?? (null === self::toDateTime($info->getCloseTime()) ? WorkflowRunStatus::Running : WorkflowRunStatus::Failed);
    }

    /**
     * Names a visibility query must not use: a server older than the status refuses the whole query
     * ("invalid ExecutionStatus value 'Paused'" on Temporal 1.25).
     */
    private const NEWER_THAN_OLDER_SERVERS = ['Paused'];

    /**
     * A filter whose statuses include one older servers do not know says what it excludes instead,
     * so it still finds that status without naming it (#506).
     */
    private static function visibilityQuery(WorkflowRunStatus $status): string
    {
        $listed = [];
        $others = [];
        foreach (self::STATUSES as [$name, $listedAs]) {
            if ($status === $listedAs) {
                $listed[] = $name;
            } elseif (!\in_array($name, self::NEWER_THAN_OLDER_SERVERS, true)) {
                $others[] = $name;
            }
        }

        return [] === array_intersect($listed, self::NEWER_THAN_OLDER_SERVERS)
            ? \sprintf('ExecutionStatus IN ("%s")', implode('", "', $listed))
            : \sprintf('ExecutionStatus NOT IN ("%s")', implode('", "', $others));
    }

    private static function decodeCursor(string $cursor): string
    {
        $raw = base64_decode($cursor, true);

        return false === $raw ? '' : $raw;
    }

    private static function toDateTime(?Timestamp $timestamp): ?\DateTimeImmutable
    {
        if (null === $timestamp || 0 === $timestamp->getSeconds()) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $timestamp->getSeconds()))->setTimezone(new \DateTimeZone('UTC'));
    }
}
