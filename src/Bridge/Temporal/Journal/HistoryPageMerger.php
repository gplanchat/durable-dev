<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Journal;

use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\History\V1\History;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;

/**
 * @internal
 */
final class HistoryPageMerger
{
    private const GRPC_NOT_FOUND = 5;

    public function __construct(
        private readonly WorkflowServiceClientInterface $client,
        private readonly string $namespace,
    ) {}

    /**
     * Full history via the server API (no worker required for reading).
     */
    public function fullHistoryForExecution(WorkflowExecution $execution): History
    {
        $req = new GetWorkflowExecutionHistoryRequest();
        $req->setNamespace($this->namespace);
        $req->setExecution($execution);

        try {
            $response = $this->client->GetWorkflowExecutionHistory($req);
        } catch (\RuntimeException $e) {
            if (self::GRPC_NOT_FOUND === $e->getCode()) {
                return new History();
            }

            throw $e;
        }
        $base = $response->getHistory();
        if (null === $base) {
            return new History();
        }
        $token = $response->getNextPageToken();
        if ('' === $token) {
            return $base;
        }

        return $this->appendPages($base, $execution, $token);
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
            $resp = $this->client->GetWorkflowExecutionHistory($req);
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
