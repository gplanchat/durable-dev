<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Journal;

use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\History\V1\History;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryRequest;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryResponse;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * @internal
 */
final class HistoryPageMerger
{
    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly string $namespace,
    ) {
    }

    /**
     * Returns a single History with all events (follows next_page_token when present).
     */
    public function fullHistoryFromPoll(PollWorkflowTaskQueueResponse $poll): History
    {
        $base = $poll->getHistory();
        if (null === $base) {
            return new History();
        }
        $token = $poll->getNextPageToken();
        if ('' === $token) {
            return $base;
        }
        $exec = $poll->getWorkflowExecution();
        if (null === $exec) {
            return $base;
        }

        return $this->appendPages($base, $exec, $token);
    }

    private function appendPages(History $accumulated, WorkflowExecution $execution, string $pageToken): History
    {
        $token = $pageToken;
        while ('' !== $token) {
            $req = new GetWorkflowExecutionHistoryRequest();
            $req->setNamespace($this->namespace);
            $req->setExecution($execution);
            $req->setNextPageToken($token);
            $call = $this->client->GetWorkflowExecutionHistory($req);
            $resp = GrpcUnary::wait($call);
            if (!$resp instanceof GetWorkflowExecutionHistoryResponse) {
                throw new \RuntimeException('Unexpected GetWorkflowExecutionHistory response type.');
            }
            $chunk = $resp->getHistory();
            if (null !== $chunk) {
                $merged = [];
                foreach ($accumulated->getEvents() as $ev) {
                    $merged[] = $ev;
                }
                foreach ($chunk->getEvents() as $ev) {
                    $merged[] = $ev;
                }
                $accumulated->setEvents($merged);
            }
            $token = $resp->getNextPageToken();
        }

        return $accumulated;
    }
}
