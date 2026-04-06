<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\Grpc\TemporalHistoryCursor;
use Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc;
use Gplanchat\Bridge\Temporal\Journal\JournalExecutionIdResolver;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Temporal\Api\Common\V1\Memo;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\SignalWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\StartWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Client-side API for driving workflow executions from application code.
 *
 * Replaces TemporalWorkflowStarter. Provides startAsync, startSync, signal, query, and update.
 * Queries and updates are delegated to WorkflowServiceExecutionRpc.
 *
 * @see \Gplanchat\Bridge\Temporal\Grpc\WorkflowServiceExecutionRpc for query/update RPCs
 */
final class WorkflowClient implements WorkflowClientInterface
{
    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly TemporalConnection $settings,
        private readonly TemporalHistoryCursor $historyCursor,
        private readonly WorkflowServiceExecutionRpc $executionRpc,
        private readonly ?WorkflowDefinitionLoader $workflowDefinitionLoader = null,
    ) {
    }

    /**
     * Starts a workflow asynchronously (fire and forget).
     *
     * @param array<string, mixed> $payload Business payload for the workflow input.
     * @return string The Temporal workflow ID used.
     */
    public function startAsync(string $workflowType, array $payload, string $executionId): string
    {
        $workflowId = $this->workflowId($executionId);
        $this->doStartWorkflow($workflowId, $workflowType, $payload, $executionId);

        return $workflowId;
    }

    /**
     * Starts a workflow and blocks until WorkflowExecutionCompleted.
     *
     * @param array<string, mixed> $payload Business payload for the workflow input.
     * @return mixed The decoded result of the workflow.
     */
    public function startSync(string $workflowType, array $payload, string $executionId): mixed
    {
        $workflowId = $this->workflowId($executionId);
        $this->doStartWorkflow($workflowId, $workflowType, $payload, $executionId);

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
     * @throws \RuntimeException when the workflow fails, is cancelled, or times out on the Temporal side.
     * @throws \RuntimeException when no completion event is found within {@code $maxRefreshes} attempts.
     */
    public function pollForCompletion(
        string $executionId,
        int $refreshIntervalMs = 500,
        int $maxRefreshes = 120,
    ): mixed {
        $workflowId = $this->workflowId($executionId);
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
                EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED     => throw new \RuntimeException(
                    \sprintf('Workflow "%s" failed: %s', $executionId, $this->extractFailureMessage($closeEvent)),
                ),
                EventType::EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT  => throw new \RuntimeException(
                    \sprintf('Workflow "%s" timed out on the Temporal side.', $executionId),
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

        throw new \RuntimeException(\sprintf(
            'Workflow "%s" did not complete within %d poll attempts (%d ms interval = ~%d s total).',
            $executionId,
            $maxRefreshes,
            $refreshIntervalMs,
            (int) ($maxRefreshes * $refreshIntervalMs / 1000),
        ));
    }

    /**
     * Delivers an external signal to a running workflow.
     *
     * @param array<string, mixed> $args Signal arguments.
     */
    public function signal(string $workflowId, string $signalName, array $args = []): void
    {
        $req = new SignalWorkflowExecutionRequest();
        $req->setNamespace($this->settings->namespace);
        $req->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $workflowId]));
        $req->setSignalName($signalName);
        $req->setIdentity($this->settings->identity);
        if ($args !== []) {
            $req->setInput(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($args)));
        }

        GrpcUnary::wait($this->client->SignalWorkflowExecution($req, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]));
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
        $request->setNamespace($this->settings->namespace);
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
    public function update(string $workflowId, string $updateName, array $args = []): mixed
    {
        $request = new \Temporal\Api\Workflowservice\V1\UpdateWorkflowExecutionRequest();
        $request->setNamespace($this->settings->namespace);
        $request->setWorkflowExecution(new WorkflowExecution(['workflow_id' => $workflowId]));

        $input = new \Temporal\Api\Update\V1\Input();
        $input->setName($updateName);
        if ($args !== []) {
            $input->setArgs(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($args)));
        }
        $updateRequest = new \Temporal\Api\Update\V1\Request();
        $updateRequest->setInput($input);
        $request->setRequest($updateRequest);
        $request->setWaitPolicy(new \Temporal\Api\Update\V1\WaitPolicy([
            'lifecycle_stage' => \Temporal\Api\Enums\V1\UpdateWorkflowExecutionLifecycleStage::UPDATE_WORKFLOW_EXECUTION_LIFECYCLE_STAGE_COMPLETED,
        ]));

        $response = $this->executionRpc->updateWorkflowExecution($request);
        $outcome = $response->getOutcome();
        if (null !== $outcome && null !== $outcome->getSuccess()) {
            $payloads = $outcome->getSuccess()->getPayloads();
            if ($payloads->count() > 0) {
                return JsonPlainPayload::decode($payloads[0]);
            }
        }

        return null;
    }

    /**
     * Computes the Temporal workflow ID for a given Durable execution ID.
     */
    public function workflowId(string $executionId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $executionId) ?? 'invalid';

        return 'durable-'.substr($safe, 0, 900);
    }

    private function doStartWorkflow(string $workflowId, string $workflowType, array $payload, string $executionId): void
    {
        $typeName = $this->resolveWorkflowTypeName($workflowType);
        $wireData = $payload === [] ? new \stdClass() : $payload;
        $inputPayload = JsonPlainPayload::encode($wireData);

        $req = new StartWorkflowExecutionRequest();
        $req->setNamespace($this->settings->namespace);
        $req->setWorkflowId($workflowId);
        $req->setWorkflowType(new WorkflowType(['name' => $typeName]));
        $req->setTaskQueue(new TaskQueue(['name' => $this->settings->workflowTaskQueue]));
        $req->setIdentity($this->settings->identity);
        $req->setInput(JsonPlainPayload::singlePayloads($inputPayload));

        $memo = new Memo();
        $memo->getFields()[JournalExecutionIdResolver::MEMO_KEY_DURABLE_EXECUTION_ID] = JsonPlainPayload::encode($executionId);
        $req->setMemo($memo);

        $call = $this->client->StartWorkflowExecution($req, [], ['timeout' => TemporalGrpcTimeouts::SHORT_US]);
        /** @var array{0: object|null, 1: \stdClass} $pair */
        $pair = $call->wait();
        [$response, $status] = $pair;
        $code = (int) ($status->code ?? -1);
        if (0 === $code) {
            if (!$response instanceof StartWorkflowExecutionResponse) {
                throw new \RuntimeException('Unexpected StartWorkflowExecution response type.');
            }

            return;
        }

        if ($this->isWorkflowAlreadyStartedGrpcError($code, (string) ($status->details ?? ''))) {
            return;
        }

        throw new \RuntimeException(\sprintf(
            'Temporal gRPC error starting workflow [%s]: %s',
            (string) $code,
            (string) ($status->details ?? ''),
        ));
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

    private function extractFailureMessage(HistoryEvent $event): string
    {
        $attr = $event->getWorkflowExecutionFailedEventAttributes();
        if (null === $attr) {
            return '(unknown failure)';
        }
        $failure = $attr->getFailure();
        if (null === $failure) {
            return '(unknown failure)';
        }

        return $failure->getMessage();
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
