<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Grpc;

use Gplanchat\Bridge\Temporal\WorkflowServiceClientInterface;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Enums\V1\EventType;
use Temporal\Api\Enums\V1\HistoryEventFilterType;
use Temporal\Api\History\V1\HistoryEvent;
use Temporal\Api\Workflowservice\V1\GetWorkflowExecutionHistoryRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;

/**
 * Lazy, cursor-based pagination of Temporal workflow history.
 *
 * Follows {@code next_page_token} page-by-page without loading the full history into memory.
 * Implements the cursor pattern defined in DUR001 §"Temporal (server API)".
 *
 * Replaces HistoryPageMerger (which merged all pages upfront into a single History object).
 */
final class TemporalHistoryCursor
{
    private const MAX_PAGE_SIZE = 200;

    private const GRPC_NOT_FOUND = 5;

    private readonly string $namespace;

    public function __construct(
        private readonly WorkflowServiceClientInterface $client,
        string|\Gplanchat\Bridge\Temporal\TemporalConnection $namespace,
    ) {
        $this->namespace = $namespace instanceof \Gplanchat\Bridge\Temporal\TemporalConnection
            ? $namespace->namespace->name()
            : $namespace;
    }

    /**
     * Yields all history events for the given execution, page by page.
     *
     * @param string $initialPageToken Empty string to start from the beginning, non-empty to resume.
     * @return \Generator<int, HistoryEvent>
     */
    public function events(WorkflowExecution $execution, string $initialPageToken = ''): \Generator
    {
        $token = $initialPageToken;

        do {
            $req = new GetWorkflowExecutionHistoryRequest();
            $req->setNamespace($this->namespace);
            $req->setExecution($execution);
            $req->setMaximumPageSize(self::MAX_PAGE_SIZE);
            if ('' !== $token) {
                $req->setNextPageToken($token);
            }

            try {
                $response = $this->client->GetWorkflowExecutionHistory($req, [], ['timeout' => TemporalGrpcTimeouts::HISTORY_US]);
            } catch (\RuntimeException $e) {
                if (self::GRPC_NOT_FOUND === $e->getCode()) {
                    return;
                }

                throw $e;
            }

            $history = $response->getHistory();
            if (null !== $history) {
                foreach ($history->getEvents() as $event) {
                    yield $event;
                }
            }

            $token = $response->getNextPageToken();
        } while ('' !== $token);
    }

    /**
     * Fetches only the close event for an execution using HISTORY_EVENT_FILTER_TYPE_CLOSE_EVENT.
     *
     * Returns the close event (WorkflowExecutionCompleted, WorkflowExecutionFailed, etc.) when the
     * workflow has terminated, or null when it is still running (or does not exist yet).
     * This is a single lightweight gRPC call, suitable for polling loops.
     */
    public function closeEvent(WorkflowExecution $execution): ?HistoryEvent
    {
        $req = new GetWorkflowExecutionHistoryRequest();
        $req->setNamespace($this->namespace);
        $req->setExecution($execution);
        $req->setMaximumPageSize(1);
        $req->setHistoryEventFilterType(HistoryEventFilterType::HISTORY_EVENT_FILTER_TYPE_CLOSE_EVENT);
        $req->setSkipArchival(true);

        try {
            $response = $this->client->GetWorkflowExecutionHistory($req, [], ['timeout' => TemporalGrpcTimeouts::HISTORY_US]);
        } catch (\RuntimeException $e) {
            // NOT_FOUND: workflow does not exist (not started yet, or unknown ID)
            if (self::GRPC_NOT_FOUND === $e->getCode()) {
                return null;
            }

            throw $e;
        }

        $history = $response->getHistory();
        if (null === $history) {
            return null;
        }

        foreach ($history->getEvents() as $event) {
            $type = $event->getEventType();
            if (EventType::EVENT_TYPE_WORKFLOW_EXECUTION_COMPLETED === $type
                || EventType::EVENT_TYPE_WORKFLOW_EXECUTION_FAILED === $type
                || EventType::EVENT_TYPE_WORKFLOW_EXECUTION_TIMED_OUT === $type
                || EventType::EVENT_TYPE_WORKFLOW_EXECUTION_CANCELED === $type
                || EventType::EVENT_TYPE_WORKFLOW_EXECUTION_TERMINATED === $type
            ) {
                return $event;
            }
        }

        return null;
    }

    /**
     * Yields events from a PollWorkflowTaskQueueResponse, following next_page_token if present.
     *
     * Events already embedded in the poll response are yielded first, then additional pages are fetched.
     *
     * @return \Generator<int, HistoryEvent>
     */
    public function eventsFromPoll(PollWorkflowTaskQueueResponse $poll): \Generator
    {
        $history = $poll->getHistory();
        if (null !== $history) {
            foreach ($history->getEvents() as $event) {
                yield $event;
            }
        }

        $token = $poll->getNextPageToken();
        if ('' === $token) {
            return;
        }

        $execution = $poll->getWorkflowExecution();
        if (null === $execution) {
            return;
        }

        yield from $this->events($execution, $token);
    }
}
