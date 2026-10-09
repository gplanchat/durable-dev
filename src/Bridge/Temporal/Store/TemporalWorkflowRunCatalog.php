<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Store;

use Google\Protobuf\Timestamp;
use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\DurableSearchAttributes;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowClient;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Exception\RunFilterUnavailableException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\Message;
use Gplanchat\Durable\Observation\NexusOperationSummary;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\NexusOperationCatalogInterface;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\WorkflowExecutionStatus;
use Temporal\Api\Workflow\V1\WorkflowExecutionInfo;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\GetSystemInfoRequest;
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
final class TemporalWorkflowRunCatalog implements WorkflowRunCatalogInterface, NexusOperationCatalogInterface
{
    private const NEXUS_EVENT_TYPES = [
        EventType::EVENT_TYPE_NEXUS_OPERATION_SCHEDULED,
        EventType::EVENT_TYPE_NEXUS_OPERATION_COMPLETED,
        EventType::EVENT_TYPE_NEXUS_OPERATION_FAILED,
        EventType::EVENT_TYPE_NEXUS_OPERATION_TIMED_OUT,
        EventType::EVENT_TYPE_NEXUS_OPERATION_CANCELED,
    ];

    private const BACKEND = 'Temporal';

    /** The first server version whose visibility queries accept STARTS_WITH (jane measured, #523). */
    private const FIRST_WITH_STARTS_WITH = '1.23.0';

    /** Whether the server accepts STARTS_WITH, once asked. */
    private ?bool $startsWith = null;

    /** Server round trips a prefixed page may take to fill up (#557). */
    private const MAX_FILL_ROUNDS = 5;

    private const GRPC_NOT_FOUND = 5;

    public function __construct(
        private readonly WorkflowServiceClientInterface $client,
        private readonly TemporalConnection $connection,
        private readonly ?TemporalHistoryCursor $historyCursor = null,
    ) {}

    /**
     * The filters read Durable's search attributes, which only a host with the switch on writes. The
     * prefix filter also needs STARTS_WITH, which servers before 1.23.0 reject (#523): the server's
     * version is asked once, and a server that names none is taken as current.
     */
    public function canFilterRuns(?WorkflowRunFilter $filter = null): bool
    {
        if (!$this->connection->searchAttributes) {
            return false;
        }

        return null === $filter?->executionIdPrefix || $this->serverHasStartsWith();
    }

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20, ?WorkflowRunFilter $filter = null): WorkflowRunPage
    {
        if (null !== $filter && !$filter->isEmpty() && !$this->canFilterRuns($filter)) {
            throw new RunFilterUnavailableException($this->connection->searchAttributes
                ? 'Filtering Temporal runs by execution-id prefix needs Temporal Server 1.23.0 or later, the first that accepts STARTS_WITH.'
                : 'Filtering Temporal runs reads Durable\'s search attributes: register them on the namespace, then turn durable.temporal.search_attributes on.');
        }
        $request = new ListWorkflowExecutionsRequest();
        $request->setNamespace($this->connection->namespace->name());
        $clauses = null === $status ? [] : [self::visibilityQuery($status)];
        // Durable's search attributes, spelled as written (#558): no WorkflowType holding a `\` matches.
        if (null !== $filter?->workflowName) {
            $clauses[] = DurableSearchAttributes::WORKFLOW_NAME . ' = ' . DurableSearchAttributes::literal(DurableSearchAttributes::value($filter->workflowName));
        }
        if (null !== $filter?->executionIdPrefix) {
            $clauses[] = DurableSearchAttributes::EXECUTION_ID . ' STARTS_WITH ' . DurableSearchAttributes::literal(DurableSearchAttributes::normalized($filter->executionIdPrefix));
        }
        if ([] !== $clauses) {
            // Each clause in parentheses once there are two: the status one may be a `NOT IN`.
            $request->setQuery(1 === \count($clauses) ? $clauses[0] : '(' . implode(') AND (', $clauses) . ')');
        }
        // A visibility store on SQLite (the dev server's) matches STARTS_WITH without case, one on
        // PostgreSQL with it: what the server returns is confirmed here, so case counts on all. The
        // runs it drops are asked for again, exactly as many as are missing, so every confirmed run
        // fits and the server's token stays exact. ponytail: bounded, past MAX_FILL_ROUNDS a page
        // comes back short, its cursor still valid; raise the bound if case-only neighbours abound.
        $prefix = null === $filter?->executionIdPrefix ? null : DurableSearchAttributes::normalized($filter->executionIdPrefix);
        $wanted = max(1, $limit);
        $token = null === $cursor ? '' : self::decodeCursor($cursor);
        $runs = [];
        $rounds = 0;
        do {
            $request->setPageSize($wanted - \count($runs));
            $request->setNextPageToken($token);
            $response = $this->client->ListWorkflowExecutions($request, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]);
            foreach ($response->getExecutions() as $info) {
                $run = self::describe($info);
                if (null !== $run && (null === $prefix || str_starts_with(self::executionIdAttribute($info), $prefix))) {
                    $runs[] = $run;
                }
            }
            $token = (string) $response->getNextPageToken();
        } while (null !== $prefix && \count($runs) < $wanted && '' !== $token && ++$rounds < self::MAX_FILL_ROUNDS);

        // The server already orders by descending start date, but the response of a custom
        // visibility query does not guarantee it: we re-sort, as the view used to do.
        usort(
            $runs,
            static fn(WorkflowRunDescription $left, WorkflowRunDescription $right): int => ($right->startedAt?->getTimestamp() ?? 0) <=> ($left->startedAt?->getTimestamp() ?? 0),
        );

        return new WorkflowRunPage($runs, '' === $token ? null : base64_encode($token));
    }

    /**
     * `DescribeWorkflowExecution` on the workflow id Durable derives from the execution id, without
     * a run id: the current run of the chain (#514). That derivation is lossy (`order/42` and
     * `order-42` share one), so a run whose memo names another execution is not the one asked for.
     * A run Durable did not start is listed under its own workflow id, and found by it second. An
     * execution the server does not know is not found; any other failure is not passed off as one.
     */
    public function findRun(ExecutionId $executionId): ?WorkflowRunDescription
    {
        // Durable's workflow id, then the lossy one a run started before #566 may still live under
        // (gone in 0.1.0-beta1), then the id itself, as a child or a foreign run has. A run counts
        // only if it was started with this execution id: the legacy id is shared by several.
        $id = $executionId->toString();
        $candidates = [WorkflowClient::workflowIdOf($id), WorkflowClient::legacyWorkflowIdOf($id), $id];
        foreach (array_unique(array_filter($candidates, static fn(?string $id): bool => null !== $id)) as $workflowId) {
            $info = $this->describeWorkflow($workflowId);
            $run = null === $info ? null : self::describe($info);
            if (null !== $run && $id === $run->executionId) {
                return $run;
            }
        }

        return null;
    }

    private function describeWorkflow(string $workflowId): ?WorkflowExecutionInfo
    {
        $request = new DescribeWorkflowExecutionRequest();
        $request->setNamespace($this->connection->namespace->name());
        $request->setExecution(new WorkflowExecution(['workflow_id' => $workflowId]));

        try {
            return $this->client->DescribeWorkflowExecution($request, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US])->getWorkflowExecutionInfo();
        } catch (\RuntimeException $failure) {
            if (self::GRPC_NOT_FOUND === $failure->getCode()) {
                return null;
            }

            throw $failure;
        }
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

    /**
     * The run's Nexus operations, read from the same history as {@see readHistory()} through the
     * converter that gives the rest of Durable its domain events.
     */
    #[\Override]
    public function readNexusOperations(WorkflowRunDescription $run): array
    {
        $workflowId = $run->groupId ?? '';
        if (null === $this->historyCursor || '' === $workflowId || '' === $run->runId) {
            return [];
        }

        $cursor = $this->historyCursor;
        $converter = new TemporalEventConverter(ExecutionId::fromString($run->executionId));
        $execution = new WorkflowExecution(['workflow_id' => $workflowId, 'run_id' => $run->runId]);

        return NexusOperationSummary::of((static function () use ($cursor, $converter, $execution): \Generator {
            foreach ($cursor->events($execution) as $event) {
                // Nexus events only: their conversion decodes no payload, where the others would
                // throw on another encoding, which readHistory() tolerates.
                if (!\in_array($event->getEventType(), self::NEXUS_EVENT_TYPES, true)) {
                    continue;
                }
                $converted = $converter->convert($event);
                if (null !== $converted) {
                    yield $converted;
                }
            }
        })());
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
                localized: new Message('backend.temporal.unreachable', ['namespace' => $this->connection->namespace->name(), 'error' => $failure->getMessage()]),
            );
        }

        return new BackendHealth(
            self::BACKEND,
            true,
            \sprintf('Connected to Temporal namespace "%s".', $this->connection->namespace->name()),
            $checkedAt,
            localized: new Message('backend.temporal.connected', ['namespace' => $this->connection->namespace->name()]),
        );
    }

    private function serverHasStartsWith(): bool
    {
        if (null === $this->startsWith) {
            $version = (string) $this->client->GetSystemInfo(new GetSystemInfoRequest(), [], ['timeout' => TemporalGrpcTimeouts::SHORT_US])->getServerVersion();
            $this->startsWith = '' === $version || version_compare(ltrim($version, 'v'), self::FIRST_WITH_STARTS_WITH, '>=');
        }

        return $this->startsWith;
    }

    private static function executionIdAttribute(WorkflowExecutionInfo $info): string
    {
        $fields = $info->getSearchAttributes()?->getIndexedFields();
        if (null === $fields || !$fields->offsetExists(DurableSearchAttributes::EXECUTION_ID)) {
            return '';
        }
        $value = json_decode($fields->offsetGet(DurableSearchAttributes::EXECUTION_ID)->getData(), true);

        return \is_string($value) ? $value : '';
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
        $status = self::statusOf($info);

        return new WorkflowRunDescription(
            runId: $runId,
            workflowName: null !== $type ? (string) $type->getName() : 'UnknownWorkflow',
            status: $status,
            startedAt: self::toDateTime($info->getStartTime()),
            endedAt: self::toDateTime($info->getCloseTime()),
            groupId: '' === $workflowId ? null : $workflowId,
            executionId: self::executionIdOf($info) ?? ('' === $workflowId ? $runId : $workflowId),
            // The memo outlives the run: an ended run waits for nothing.
            waitingOn: $status->isRunning() ? self::memoString($info, JournalExecutionIdResolver::MEMO_KEY_DURABLE_WAITING_ON) : null,
        );
    }

    /**
     * The id the application started the run with, from the memo Durable writes at start and at
     * each continue-as-new (#514, #560). Absent on a run Durable did not start.
     */
    private static function executionIdOf(WorkflowExecutionInfo $info): ?string
    {
        return self::memoString($info, JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID);
    }

    /**
     * A memo field Durable wrote as a JSON string, or `null` when absent, empty or not ours.
     */
    private static function memoString(WorkflowExecutionInfo $info, string $key): ?string
    {
        $field = $info->getMemo()?->getFields()[$key] ?? null;

        try {
            $value = null === $field ? null : JsonPlainPayload::decode($field);
        } catch (\JsonException) {
            // Another client's memo: it names nothing, and must not take the page down with it.
            return null;
        }

        return \is_string($value) && '' !== $value ? $value : null;
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
