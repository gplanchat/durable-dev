<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Journal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Bridge\Temporal\TemporalConnection;
use Temporal\Api\Enums\V1\QueryResultType;
use Temporal\Api\Query\V1\WorkflowQueryResult;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedRequest;
use Temporal\Api\Workflowservice\V1\RespondWorkflowTaskCompletedResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Completes a workflow task for the Durable journal workflow (signals + readStream query).
 */
final class JournalWorkflowTaskProcessor
{
    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly TemporalConnection $settings,
        private readonly HistoryPageMerger $historyMerger,
    ) {
    }

    public function process(PollWorkflowTaskQueueResponse $poll): void
    {
        $token = $poll->getTaskToken();
        if ('' === $token) {
            return;
        }

        $history = $this->historyMerger->fullHistoryFromPoll($poll);
        $journalRows = JournalStateResolver::journalRowsFromHistory($history, $this->settings->signalAppend);

        $req = new RespondWorkflowTaskCompletedRequest();
        $req->setTaskToken($token);
        $req->setNamespace($this->settings->namespace);
        $req->setIdentity($this->settings->identity);

        $queries = $poll->getQueries();
        foreach ($queries as $queryId => $query) {
            if ($query->getQueryType() !== $this->settings->queryReadStream) {
                continue;
            }
            $answer = JsonPlainPayload::encode($journalRows);
            $result = new WorkflowQueryResult();
            $result->setResultType(QueryResultType::QUERY_RESULT_TYPE_ANSWERED);
            $result->setAnswer(JsonPlainPayload::singlePayloads($answer));
            $req->getQueryResults()[$queryId] = $result;
        }

        if ($poll->hasQuery()) {
            $legacy = $poll->getQuery();
            if (null !== $legacy && $legacy->getQueryType() === $this->settings->queryReadStream) {
                $answer = JsonPlainPayload::encode($journalRows);
                $result = new WorkflowQueryResult();
                $result->setResultType(QueryResultType::QUERY_RESULT_TYPE_ANSWERED);
                $result->setAnswer(JsonPlainPayload::singlePayloads($answer));
                $req->getQueryResults()['legacy'] = $result;
            }
        }

        $call = $this->client->RespondWorkflowTaskCompleted($req);
        $done = GrpcUnary::wait($call);
        if (!$done instanceof RespondWorkflowTaskCompletedResponse) {
            throw new \RuntimeException('Unexpected RespondWorkflowTaskCompleted response type.');
        }
    }
}
