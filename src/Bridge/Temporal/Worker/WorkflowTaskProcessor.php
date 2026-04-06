<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Worker;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\Grpc\TemporalGrpcTimeouts;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Temporal\Api\Enums\V1\QueryResultType;
use Temporal\Api\Query\V1\WorkflowQueryResult;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Workflow task poll → execute → respond loop (Temporal native backend).
 *
 * Polls one workflow task, delegates replay to WorkflowTaskRunner, then sends commands
 * back via RespondWorkflowTaskCompleted. Query handling (signal, query, update) will be
 * added in the signal-query-update phase (DUR ADR, WorkflowTaskProcessor todo).
 *
 * Replaces JournalWorkflowTaskProcessor for the native execution path.
 */
final class WorkflowTaskProcessor
{
    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly TemporalConnection $settings,
        private readonly WorkflowTaskRunner $runner,
    ) {
    }

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

        $result = $this->runner->run($poll);
        $commands = $result->commands;

        $queryResults = [];
        if (null !== $result->environment) {
            $queryResults = $this->handleQueries($poll, $result->environment);
        }

        $this->respond($poll->getTaskToken(), $commands, $queryResults);

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

    private function pollOnce(): PollWorkflowTaskQueueResponse
    {
        $req = new PollWorkflowTaskQueueRequest();
        $req->setNamespace($this->settings->namespace);
        $req->setTaskQueue(new TaskQueue(['name' => $this->settings->workflowTaskQueue]));
        $req->setIdentity($this->settings->identity);

        $call = $this->client->PollWorkflowTaskQueue($req, [], ['timeout' => TemporalGrpcTimeouts::LONG_POLL_US]);
        $resp = GrpcUnary::wait($call);
        if (!$resp instanceof PollWorkflowTaskQueueResponse) {
            throw new \RuntimeException('Unexpected PollWorkflowTaskQueue response type.');
        }

        return $resp;
    }

    /**
     * Answers Temporal queries by calling the registered #[QueryMethod] handlers on the environment.
     *
     * @return array<string, WorkflowQueryResult>
     */
    private function handleQueries(
        PollWorkflowTaskQueueResponse $poll,
        \Gplanchat\Durable\WorkflowEnvironment $environment,
    ): array {
        $results = [];

        foreach ($poll->getQueries() as $queryId => $query) {
            $queryType = $query->getQueryType();
            $queryResult = new WorkflowQueryResult();

            if ($environment->hasQueryHandler($queryType)) {
                try {
                    $answer = $environment->callQueryHandler($queryType, []);
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
     * @param list<\Temporal\Api\Command\V1\Command> $commands
     * @param array<string, WorkflowQueryResult>     $queryResults
     */
    private function respond(string $taskToken, array $commands, array $queryResults = []): void
    {
        $req = new RespondWorkflowTaskCompletedRequest();
        $req->setTaskToken($taskToken);
        $req->setNamespace($this->settings->namespace);
        $req->setIdentity($this->settings->identity);
        if ($commands !== []) {
            $req->setCommands($commands);
        }
        foreach ($queryResults as $queryId => $queryResult) {
            $req->getQueryResults()[$queryId] = $queryResult;
        }

        $call = $this->client->RespondWorkflowTaskCompleted($req, [], ['timeout' => TemporalGrpcTimeouts::RESPOND_WORKFLOW_TASK_US]);
        /** @var array{0: RespondWorkflowTaskCompletedResponse|null, 1: \stdClass} $pair */
        $pair = $call->wait();
        [$done, $status] = $pair;
        $code = (int) ($status->code ?? -1);

        if (0 === $code) {
            return;
        }

        if (5 === $code) {
            // NOT_FOUND: task token is stale or workflow was already closed (e.g. replayed from a prior attempt).
            return;
        }

        throw new \RuntimeException(\sprintf(
            'Temporal gRPC error responding to workflow task [%d]: %s',
            $code,
            (string) ($status->details ?? ''),
        ));
    }
}
