<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Codec\JsonPlainPayload;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Store\EventSerializer;
use Gplanchat\Durable\Store\EventStoreInterface;
use Temporal\Api\Common\V1\WorkflowExecution;
use Temporal\Api\Common\V1\WorkflowType;
use Temporal\Api\Enums\V1\WorkflowIdConflictPolicy;
use Temporal\Api\Query\V1\WorkflowQuery;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\QueryWorkflowRequest;
use Temporal\Api\Workflowservice\V1\QueryWorkflowResponse;
use Temporal\Api\Workflowservice\V1\SignalWithStartWorkflowExecutionRequest;
use Temporal\Api\Workflowservice\V1\SignalWithStartWorkflowExecutionResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * EventStore backed by a Temporal workflow journal (signals + readStream query).
 */
final class TemporalJournalEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly TemporalConnection $settings,
    ) {
    }

    public function append(Event $event): void
    {
        $row = EventSerializer::serialize($event);
        $payload = JsonPlainPayload::encode($row);
        $payloads = JsonPlainPayload::singlePayloads($payload);

        $wfId = $this->settings->journalWorkflowId($event->executionId());

        $req = new SignalWithStartWorkflowExecutionRequest();
        $req->setNamespace($this->settings->namespace);
        $req->setWorkflowId($wfId);
        $req->setWorkflowType(new WorkflowType(['name' => $this->settings->workflowType]));
        $req->setTaskQueue(new TaskQueue(['name' => $this->settings->journalTaskQueue]));
        $req->setSignalName($this->settings->signalAppend);
        $req->setSignalInput($payloads);
        $req->setIdentity($this->settings->identity);
        $req->setWorkflowIdConflictPolicy(WorkflowIdConflictPolicy::WORKFLOW_ID_CONFLICT_POLICY_USE_EXISTING);

        $call = $this->client->SignalWithStartWorkflowExecution($req);
        $started = GrpcUnary::wait($call);
        if (!$started instanceof SignalWithStartWorkflowExecutionResponse) {
            throw new \RuntimeException('Unexpected SignalWithStartWorkflowExecution response type.');
        }
    }

    public function readStream(string $executionId): iterable
    {
        $wfId = $this->settings->journalWorkflowId($executionId);

        $exec = new WorkflowExecution();
        $exec->setWorkflowId($wfId);
        $exec->setRunId('');

        $query = new WorkflowQuery();
        $query->setQueryType($this->settings->queryReadStream);

        $req = new QueryWorkflowRequest();
        $req->setNamespace($this->settings->namespace);
        $req->setExecution($exec);
        $req->setQuery($query);

        $call = $this->client->QueryWorkflow($req);
        $resp = GrpcUnary::wait($call);
        if (!$resp instanceof QueryWorkflowResponse) {
            throw new \RuntimeException('Unexpected QueryWorkflow response type.');
        }

        if ($resp->hasQueryRejected()) {
            throw new \RuntimeException('Temporal query rejected (workflow status): '.(string) $resp->getQueryRejected()->getStatus());
        }

        $result = $resp->getQueryResult();
        if (null === $result || 0 === \count($result->getPayloads())) {
            return;
        }

        $first = $result->getPayloads()[0];
        $decoded = JsonPlainPayload::decode($first);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Temporal readStream: expected JSON array.');
        }

        foreach ($decoded as $row) {
            if (!\is_array($row)) {
                continue;
            }
            /* @var array<string, mixed> $row */
            yield EventSerializer::deserialize($row);
        }
    }

    public function countEventsInStream(string $executionId): int
    {
        $n = 0;
        foreach ($this->readStream($executionId) as $_) {
            ++$n;
        }

        return $n;
    }
}
