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
    private const GRPC_NOT_FOUND = 5;

    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly string $namespace,
    ) {
    }

    /**
     * Historique complet via API serveur (aucun worker requis pour la lecture).
     */
    public function fullHistoryForExecution(WorkflowExecution $execution): History
    {
        $req = new GetWorkflowExecutionHistoryRequest();
        $req->setNamespace($this->namespace);
        $req->setExecution($execution);
        $call = $this->client->GetWorkflowExecutionHistory($req);
        /** @var array{0: GetWorkflowExecutionHistoryResponse|null, 1: \stdClass} $pair */
        $pair = $call->wait();
        [$response, $status] = $pair;
        $code = $status->code ?? -1;
        if (self::GRPC_NOT_FOUND === $code) {
            return new History();
        }
        if (0 !== $code) {
            throw new \RuntimeException(\sprintf('Temporal gRPC error [%s]: %s', (string) $code, (string) ($status->details ?? '')));
        }
        if (null === $response) {
            throw new \RuntimeException('Temporal gRPC returned empty response.');
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
