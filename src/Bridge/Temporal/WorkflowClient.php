<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\WorkflowFailureCodec;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Bridge\Temporal\Worker\TemporalPolicyMapper;
use Gplanchat\Durable\CronSchedule;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowFailedException;
use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\Exception\WorkflowTerminatedException;
use Gplanchat\Durable\Exception\WorkflowTimedOutException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowStartOptions;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\DescribeWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;

/**
 * Client-side API for driving workflow executions from application code.
 *
 * Replaces TemporalWorkflowStarter. Provides startAsync, startSync, signal, query, and update.
 * Queries and updates are delegated to WorkflowServiceExecutionRpc.
 *
 * @see \Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc for query/update RPCs
 */
final readonly class WorkflowClient implements WorkflowClientInterface
{
    private const GRPC_NOT_FOUND = 5;

    public function __construct(
        private readonly WorkflowServiceClientInterface $client,
        private readonly TemporalConnection $settings,
        private readonly TemporalHistoryCursor $historyCursor,
        private readonly WorkflowServiceExecutionRpc $executionRpc,
        private readonly ?WorkflowDefinitionLoader $workflowDefinitionLoader = null,
    ) {}

    /**
     * Starts a workflow asynchronously (fire and forget).
     *
     * @param array<string, mixed> $payload Business payload for the workflow input.
     * @return ExecutionId The execution started; {@see workflowId()} gives its Temporal workflow id.
     */
    public function startAsync(
        string $workflowType,
        array $payload,
        ExecutionId $executionId,
        ?WorkflowStartOptions $options = null,
    ): ExecutionId {
        $id = $executionId->toString();
        $this->doStartWorkflow(self::workflowIdOf($id), $workflowType, $payload, $id, $options);

        return $executionId;
    }

    /**
     * Starts a **recurring** execution: the server relaunches one at every cron deadline.
     *
     * A Temporal cron is not an external scheduler — it is the same logical execution,
     * relaunched with a fresh history. The next one is not started as long as the previous one
     * has not finished: a missed deadline is skipped, not caught up.
     *
     * @param array<string, mixed> $payload
     */
    public function startCron(
        string $workflowType,
        array $payload,
        string $executionId,
        CronSchedule|string $schedule,
    ): string {
        $this->startAsync($workflowType, $payload, ExecutionId::fromString($executionId), WorkflowStartOptions::cron($schedule));

        return self::workflowIdOf($executionId);
    }

    /**
     * Starts a workflow and blocks until WorkflowExecutionCompleted.
     *
     * @param array<string, mixed> $payload Business payload for the workflow input.
     * @return mixed The decoded result of the workflow.
     */
    public function startSync(
        string $workflowType,
        array $payload,
        ExecutionId $executionId,
        ?WorkflowStartOptions $options = null,
    ): mixed {
        $workflowId = self::workflowIdOf($executionId->toString());
        $this->doStartWorkflow($workflowId, $workflowType, $payload, $executionId->toString(), $options);

        return $this->waitForCompletion($workflowId);
    }

    /**
     * Polls Temporal for workflow completion, retrying periodically until the workflow terminates.
     *
     * Uses {@see TemporalHistoryCursor::closeEvent()} with HISTORY_EVENT_FILTER_TYPE_CLOSE_EVENT for
     * efficiency: one lightweight gRPC call per attempt, no full history traversal.
     * Correct in multi-process setups where the HTTP process and worker are separate.
     *
     * @param int $refreshIntervalMs Milliseconds between poll attempts (default: 500 ms).
     * @param int $maxRefreshes      Maximum number of attempts before throwing (default: 120 = 60 s total).
     *
     * @throws \Throwable                   when the workflow fails: the exception the journal backends raise
     *                                      for that failure, or {@see WorkflowFailedException} when it cannot be rebuilt.
     * @throws WorkflowCancelledException  when the workflow was cancelled.
     * @throws WorkflowTimedOutException   when its execution or run timeout elapsed.
     * @throws WorkflowTerminatedException when it was terminated from outside.
     * @throws WorkflowStuckException when no completion event is found within {@code $maxRefreshes} attempts.
     */
    public function pollForCompletion(
        string $executionId,
        int $refreshIntervalMs = 500,
        int $maxRefreshes = 120,
    ): mixed {
        $workflowId = $this->workflowId(ExecutionId::fromString($executionId));
        $execution = new WorkflowExecution(['workflow_id' => $workflowId]);

        for ($attempt = 0; $attempt < $maxRefreshes; $attempt++) {
            if ($attempt > 0) {
                usleep($refreshIntervalMs * 1000);
            }

            $closeEvent = $this->historyCursor->closeEvent($execution);
            if (null === $closeEvent) {
                continue;
            }

            return match ($closeEvent->getEventType()) {
                EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED => $this->decodeCompletedResult($closeEvent),
                EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED     => throw WorkflowFailureCodec::toThrowable(
                    $executionId,
                    $closeEvent->getWorkflowExecutionFailedEventAttributes()?->getFailure(),
                ),
                EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCELED   => throw new WorkflowCancelledException(
                    $executionId,
                    $this->cancellationReason($closeEvent),
                ),
                EventType::EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT  => throw new WorkflowTimedOutException($executionId),
                EventType::EVENT_TYPE_WORKFLOW_EXECUTION_TERMINATED => throw new WorkflowTerminatedException(
                    $executionId,
                    (string) $closeEvent->getWorkflowExecutionTerminatedEventAttributes()?->getReason(),
                ),
                default => throw new \RuntimeException(
                    \sprintf(
                        'Workflow "%s" terminated unexpectedly (event type %d).',
                        $executionId,
                        $closeEvent->getEventType(),
                    ),
                ),
            };
        }

        throw WorkflowStuckException::pollsExhausted($executionId, $maxRefreshes, $refreshIntervalMs);
    }

    /**
     * Delivers an external signal to a running workflow.
     *
     * The name is given as a {@see \BackedEnum}, as on the workflow side
     * ({@see \Gplanchat\Durable\WorkflowEnvironment::onSignal()}), so that the emitter and the
     * wait share one and the same enumeration rather than two literals to keep in agreement.
     *
     * @param array<string, mixed> $args Signal arguments.
     */
    public function signal(string $workflowId, \BackedEnum|string $signalName, array $args = [], ?string $requestId = null): void
    {
        $req = new SignalWorkflowExecutionRequest();
        $req->setNamespace($this->settings->namespace->name());
        $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $workflowId]));
        $req->setSignalName($signalName instanceof \BackedEnum ? (string) $signalName->value : $signalName);
        $req->setIdentity($this->settings->identity);
        $req->setRequestId($requestId ?? bin2hex(random_bytes(16)));
        if ($args !== []) {
            $req->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($args)));
        }

        $this->client->SignalWorkflowExecution($req, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]);
    }

    /**
     * Evaluates a query on a running workflow.
     *
     * @param array<string, mixed> $args Query arguments.
     * @return mixed The decoded query result.
     */
    public function query(string $workflowId, string $queryType, array $args = []): mixed
    {
        $request = new \Temporal\Api\Workflowservice\V1\QueryWorkflowRequest();
        $request->setNamespace($this->settings->namespace->name());
        $request->setExecution(new WorkflowExecution(['workflow_id' => $workflowId]));

        $query = new \Temporal\Api\Query\V1\WorkflowQuery();
        $query->setQueryType($queryType);
        if ($args !== []) {
            $query->setQueryArgs(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($args)));
        }
        $request->setQuery($query);

        $response = $this->executionRpc->queryWorkflow($request);
        $result = $response->getQueryResult();
        if (null === $result) {
            return null;
        }
        $payloads = $result->getPayloads();
        if (0 === $payloads->count()) {
            return null;
        }

        return JsonPlainPayload::decode($payloads[0]);
    }

    /**
     * Delivers a transactional update to a running workflow and waits for the result.
     *
     * @param array<string, mixed> $args Update arguments.
     * @return mixed The decoded update result.
     */
    public function update(string $workflowId, string $updateName, array $args = [], ?string $updateId = null): mixed
    {
        $request = new \Temporal\Api\Workflowservice\V1\UpdateWorkflowExecutionRequest();
        $request->setNamespace($this->settings->namespace->name());
        $request->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $workflowId]));

        $input = new \Temporal\Api\Update\V1\Input();
        $input->setName($updateName);
        if ($args !== []) {
            $input->setArgs(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($args)));
        }
        $updateRequest = new \Temporal\Api\Update\V1\Request();
        // Without meta, the server flatly refuses: "Update meta is not set on request".
        $updateRequest->setMeta(new \Temporal\Api\Update\V1\Meta([
            'update_id' => $updateId ?? bin2hex(random_bytes(16)),
            'identity' => $this->settings->identity,
        ]));
        $updateRequest->setInput($input);
        $request->setRequest($updateRequest);
        $request->setWaitPolicy(new \Temporal\Api\Update\V1\WaitPolicy([
            'lifecycle_stage' => \Temporal\Api\Enums\V1\UpdateWorkflowExecutionLifecycleStage::UPDATE_WORKFLOW_EXECUTION_LIFECYCLE_STAGE_COMPLETED,
        ]));

        $response = $this->executionRpc->updateWorkflowExecution($request);
        $outcome = $response->getOutcome();
        if (null !== $outcome && null !== $outcome->getFailure()) {
            // The update failed, not the execution: the caller receives the failure, and the
            // workflow carries on its way.
            throw new DurableUpdateFailedException($updateName, (string) $outcome->getFailure()->getMessage());
        }
        if (null !== $outcome && null !== $outcome->getSuccess()) {
            $payloads = $outcome->getSuccess()->getPayloads();
            if ($payloads->count() > 0) {
                return JsonPlainPayload::decode($payloads[0]);
            }
        }

        return null;
    }

    /**
     * The workflow id a run of this execution lives under, to address it: signal, update, query,
     * history. Starts use {@see workflowIdOf()}, never this.
     *
     * A run started before #566 may still live under its {@see legacyWorkflowIdOf()}: that one is
     * taken only when the new id holds no run and the old one holds a run started with this very
     * execution id, per its memo. Ids the mapping leaves alone never pay the lookup. That fallback
     * goes in 0.1.0-beta1.
     */
    public function workflowId(ExecutionId $executionId): string
    {
        $id = $executionId->toString();
        $current = self::workflowIdOf($id);
        $legacy = self::legacyWorkflowIdOf($id);
        if (null === $legacy || null !== $this->describedExecutionId($current)) {
            return $current;
        }

        return $id === $this->describedExecutionId($legacy) ? $legacy : $current;
    }

    /**
     * The workflow id a run of this execution starts under, one per execution id (#566).
     *
     * An id made only of `[a-zA-Z0-9._-]`, of at most 900 characters, keeps `durable-<id>`: UUIDs and
     * ULIDs do, so their runs keep the id they were started under. Any other id keeps a sanitised
     * prefix, then `~`, which sanitisation never writes, then the SHA-256 of the whole id: two ids
     * never share one, and a hashed id never spells a kept one. At most 908 characters, as before.
     */
    public static function workflowIdOf(string $executionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $executionId) ?? '';
        if ('' !== $executionId && $safe === $executionId && \strlen($executionId) <= 900) {
            return 'durable-' . $executionId;
        }

        return 'durable-' . substr($safe, 0, 835) . '~' . hash('sha256', $executionId);
    }

    /**
     * The workflow id the lossy mapping before #566 gave this execution, when it differs from
     * {@see workflowIdOf()}; `null` when the two agree.
     *
     * @deprecated the fallback to the legacy workflow id goes in 0.1.0-beta1
     */
    public static function legacyWorkflowIdOf(string $executionId): ?string
    {
        $legacy = 'durable-' . substr(preg_replace('/[^a-zA-Z0-9._-]/', '-', $executionId) ?? '', 0, 900);

        return $legacy === self::workflowIdOf($executionId) ? null : $legacy;
    }

    /**
     * The execution id the run under this workflow id was started with, per its memo; `null` when
     * no run lives there, or one Durable did not start.
     */
    private function describedExecutionId(string $workflowId): ?string
    {
        $request = new DescribeWorkflowExecutionRequest();
        $request->setNamespace($this->settings->namespace->name());
        $request->setExecution(new WorkflowExecution(['workflow_id' => $workflowId]));

        try {
            $info = $this->client->DescribeWorkflowExecution($request, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US])->getWorkflowExecutionInfo();
        } catch (\RuntimeException $failure) {
            if (self::GRPC_NOT_FOUND === $failure->getCode()) {
                return null;
            }

            throw $failure;
        }

        return JournalExecutionIdResolver::fromMemo($info?->getMemo()) ?? (null === $info ? null : '');
    }

    /** @param array<string, mixed> $payload */
    private function doStartWorkflow(
        string $workflowId,
        string $workflowType,
        array $payload,
        string $executionId,
        ?WorkflowStartOptions $options = null,
    ): void {
        $typeName = $this->resolveWorkflowTypeName($workflowType);
        $wireData = $payload === [] ? new \stdClass() : $payload;
        $inputPayload = JsonPlainPayload::encode($wireData);
        $options ??= WorkflowStartOptions::defaults();

        $req = new StartWorkflowExecutionRequest();
        $req->setNamespace($this->settings->namespace->name());
        $req->setWorkflowId($workflowId);
        $req->setWorkflowType(new WorkflowType(['name' => $typeName]));
        $req->setTaskQueue(new TaskQueue([
            'name' => ($options->taskQueue ?? $this->settings->workflowTaskQueue)->name(),
        ]));
        $req->setIdentity($this->settings->identity);
        $req->setInput(JsonPlainPayload::singlePayloads($inputPayload));

        if (null !== $options->cronSchedule) {
            $req->setCronSchedule($options->cronSchedule->toExpression());
        }
        $req->setWorkflowIdReusePolicy(TemporalPolicyMapper::idReusePolicy($options->workflowIdReusePolicy));
        TemporalPolicyMapper::applyWorkflowTimeouts($options->timeouts, $req);
        TemporalPolicyMapper::applySearchAttributes(DurableSearchAttributes::of($this->settings, $executionId, $typeName, $options->searchAttributes), $req);

        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID] = JsonPlainPayload::encode($executionId);
        $req->setMemo($memo);

        try {
            $this->client->StartWorkflowExecution($req, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]);
        } catch (\RuntimeException $e) {
            if ($this->isWorkflowAlreadyStartedGrpcError($e->getCode(), $e->getMessage())) {
                return;
            }

            throw $e;
        }
    }

    private function decodeCompletedResult(HistoryEvent $event): mixed
    {
        $attr = $event->getWorkflowExecutionCompletedEventAttributes();
        if (null === $attr) {
            return null;
        }
        $result = $attr->getResult();
        if (null === $result) {
            return null;
        }
        $payloads = $result->getPayloads();
        if (0 === $payloads->count()) {
            return null;
        }

        return JsonPlainPayload::decode($payloads[0]);
    }

    /** The reason {@see \Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowCommandBuffer::cancelWorkflow()} wrote into the details. */
    private function cancellationReason(HistoryEvent $event): string
    {
        $payloads = $event->getWorkflowExecutionCanceledEventAttributes()?->getDetails()?->getPayloads();
        $details = null === $payloads || 0 === $payloads->count() ? null : JsonPlainPayload::decode($payloads[0]);

        return \is_array($details) && \is_string($details['reason'] ?? null) ? $details['reason'] : '';
    }

    /**
     * Polls history until WORKFLOW_EXECUTION_COMPLETED and returns the decoded result.
     * Used by startSync() for in-process scenarios.
     */
    private function waitForCompletion(string $workflowId): mixed
    {
        $execution = new WorkflowExecution(['workflow_id' => $workflowId]);

        foreach ($this->historyCursor->events($execution) as $event) {
            if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED !== $event->getEventType()) {
                continue;
            }

            return $this->decodeCompletedResult($event);
        }

        return null;
    }

    private function resolveWorkflowTypeName(string $workflowType): string
    {
        if (null !== $this->workflowDefinitionLoader) {
            return $this->workflowDefinitionLoader->aliasForTemporalInterop($workflowType);
        }

        return $workflowType;
    }

    private static function isWorkflowAlreadyStartedGrpcError(int $code, string $details): bool
    {
        if (6 === $code) {
            return true;
        }

        return str_contains($details, 'already running')
            || str_contains($details, 'Workflow execution is already running');
    }
}
