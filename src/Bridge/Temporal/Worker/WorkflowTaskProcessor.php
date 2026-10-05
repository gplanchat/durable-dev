<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Codec\PayloadDecodeFailure;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Psr\Log\LoggerInterface;
use Temporal\Api\Enums\V1\QueryResultType;
use Temporal\Api\Enums\V1\WorkflowTaskFailedCause;
use Temporal\Api\Failure\V1\Failure;
use Temporal\Api\Query\V1\WorkflowQueryResult;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskFailedRequest;

/**
 * Workflow task poll → execute → respond loop (Temporal native backend).
 *
 * Polls one workflow task, delegates replay to WorkflowTaskRunner, then sends commands
 * back via RespondWorkflowTaskCompleted, along with the protocol messages that accompany them
 * ({@see UpdateProtocol}).
 */
final readonly class WorkflowTaskProcessor
{
    public function __construct(
        private readonly WorkflowServiceClientInterface $client,
        private readonly TemporalConnection $settings,
        private readonly WorkflowTaskRunner $runner,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Long-polls one workflow task, replays the history, and sends the commands back.
     *
     * Returns true if a non-empty task was processed, false on an empty-poll heartbeat.
     */
    public function processOne(): bool
    {
        $poll = $this->pollOnce();
        if ('' === $poll->getTaskToken()) {
            return false;
        }

        try {
            $result = $this->runner->run($poll);
        } catch (WorkflowTaskFailure|PayloadDecodeFailure $e) {
            // A payload on a later history page is decoded here, during replay, not at poll time (#824);
            // a history that does not read, its started memo included, fails the same way (#890).
            if ($e instanceof PayloadDecodeFailure) {
                // The server gets the message only: the original error and its event stay here (#936).
                $this->logger?->error('A workflow task payload cannot be read; the worker answers the task as failed.', [
                    'exception' => $e->getPrevious() ?? $e,
                    'event_id' => $e->eventId,
                    'workflow_id' => $poll->getWorkflowExecution()?->getWorkflowId(),
                    'run_id' => $poll->getWorkflowExecution()?->getRunId(),
                ]);
            }
            $this->respondTaskFailed($poll->getTaskToken(), $e);

            return true;
        }
        $commands = $result->commands;

        $queryResults = [];
        if (null !== $result->queryHandlers) {
            $queryResults = $this->handleQueries($poll, $result->queryHandlers);
        }

        $this->respond($poll->getTaskToken(), $commands, $queryResults, $result->messages);

        return true;
    }

    /**
     * Runs the poll–execute–respond loop indefinitely (blocking).
     *
     * Provide a callable(bool): bool returning false to stop the loop (useful for testing/graceful shutdown).
     * The callable receives whether the last poll produced a non-empty task.
     *
     * @param callable(bool): bool|null $shouldContinue
     */
    public function run(?callable $shouldContinue = null): void
    {
        while (true) {
            $processed = $this->processOne();
            if (null !== $shouldContinue && !$shouldContinue($processed)) {
                break;
            }
        }
    }

    /**
     * Fails the **task**, not the execution: no command is emitted, so the history learns nothing
     * of this attempt and the server hands the task back.
     *
     * The server may reject this answer with NOT_FOUND (5), when the task has already timed out,
     * or with InvalidArgument (3), when it has already failed and rescheduled the task: the same
     * two codes {@see respond()} lets through. Either way the token is dead and the history holds
     * nothing from this attempt, so the worker logs the rejection and keeps polling (#863).
     *
     * `cause` stays at its default value, `UNSPECIFIED`: the causes the server enumerates describe
     * worker protocol faults, and a replay divergence is not one of them. Inventing one would tell
     * the server something false. A payload that fails to decode gets the cause a failed decode
     * gets at poll time ({@see \Gplanchat\Bridge\Temporal\Codec\PayloadCodecWorkflowServiceClient}).
     */
    private function respondTaskFailed(string $taskToken, WorkflowTaskFailure|PayloadDecodeFailure $reason): void
    {
        $failure = new Failure();
        $failure->setMessage($reason->getMessage());
        $failure->setSource('DurableWorkflowWorker');

        $req = new RespondWorkflowTaskFailedRequest();
        $req->setNamespace($this->settings->namespace->name());
        $req->setIdentity($this->settings->identity);
        $req->setTaskToken($taskToken);
        $req->setFailure($failure);
        if ($reason instanceof PayloadDecodeFailure) {
            $req->setCause(WorkflowTaskFailedCause::WORKFLOW_TASK_FAILED_CAUSE_WORKFLOW_WORKER_UNHANDLED_FAILURE);
        }

        try {
            $this->client->RespondWorkflowTaskFailed($req, [], ['timeout' => TemporalGrpcTimeouts::RESPOND_WORKFLOW_TASK_US]);
        } catch (\RuntimeException $e) {
            if (5 !== $e->getCode() && 3 !== $e->getCode()) {
                throw $e;
            }
            $this->logger?->warning('Temporal rejected a workflow task failure answer; the worker keeps polling.', [
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function pollOnce(): PollWorkflowTaskQueueResponse
    {
        $req = new PollWorkflowTaskQueueRequest();
        $req->setNamespace($this->settings->namespace->name());
        $req->setTaskQueue(new TaskQueue(['name' => $this->settings->workflowTaskQueue->name()]));
        $req->setIdentity($this->settings->identity);

        $resp = $this->client->PollWorkflowTaskQueue($req, [], ['timeout' => TemporalGrpcTimeouts::LONG_POLL_US]);

        return $resp;
    }

    /**
     * Answers Temporal queries from the handlers the definition loader registered.
     *
     * @return array<string, WorkflowQueryResult>
     */
    private function handleQueries(
        PollWorkflowTaskQueueResponse $poll,
        \Gplanchat\Durable\Workflow\QueryHandlerRegistry $queries,
    ): array {
        $results = [];

        foreach ($poll->getQueries() as $queryId => $query) {
            $queryType = $query->getQueryType();
            $queryResult = new WorkflowQueryResult();

            if ($queries->has($queryType)) {
                try {
                    $answer = $queries->call($queryType, []);
                    $queryResult->setResultType(QueryResultType::QUERY_RESULT_TYPE_ANSWERED);
                    $queryResult->setAnswer(JsonPlainPayload::singlePayloads(JsonPlainPayload::encode($answer)));
                } catch (\Throwable) {
                    $queryResult->setResultType(QueryResultType::QUERY_RESULT_TYPE_FAILED);
                }
            } else {
                $queryResult->setResultType(QueryResultType::QUERY_RESULT_TYPE_FAILED);
            }

            $results[(string) $queryId] = $queryResult;
        }

        return $results;
    }

    /**
     * @param list<\Temporal\Api\Command\V1\Command>  $commands
     * @param array<string, WorkflowQueryResult>      $queryResults
     * @param list<\Temporal\Api\Protocol\V1\Message> $messages
     */
    private function respond(string $taskToken, array $commands, array $queryResults = [], array $messages = []): void
    {
        $req = new RespondWorkflowTaskCompletedRequest();
        $req->setTaskToken($taskToken);
        $req->setNamespace($this->settings->namespace->name());
        $req->setIdentity($this->settings->identity);
        if ($commands !== []) {
            $req->setCommands($commands);
        }
        if ($messages !== []) {
            $req->setMessages($messages);
        }
        foreach ($queryResults as $queryId => $queryResult) {
            $req->getQueryResults()[$queryId] = $queryResult;
        }

        try {
            $this->client->RespondWorkflowTaskCompleted($req, [], ['timeout' => TemporalGrpcTimeouts::RESPOND_WORKFLOW_TASK_US]);
        } catch (\RuntimeException $e) {
            // NOT_FOUND: task token is stale or workflow was already closed (e.g. replayed from a prior attempt).
            if (5 === $e->getCode()) {
                $this->logger?->warning('Temporal rejected a workflow task completion; the worker keeps polling.', [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ]);

                return;
            }
            // INVALID_ARGUMENT: the server has already failed and rescheduled the task, for instance
            // on BadSearchAttributes while a continue-as-new's mapping settles (#840).
            if (3 === $e->getCode()) {
                $this->logger?->warning('Temporal rejected a workflow task completion; the server reschedules the task.', [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ]);

                return;
            }

            throw $e;
        }
    }
}
