<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Gplanchat\Bridge\Temporal\Grpc\GrpcCallOptions;
use Gplanchat\Bridge\Temporal\Grpc\GrpcUnary;
use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;
use Temporal\Api\Workflowservice\V1\WorkflowServiceClient;

/**
 * Long-poll one workflow task from the journal task queue.
 */
final class TemporalJournalGrpcPoller
{
    public function __construct(
        private readonly WorkflowServiceClient $client,
        private readonly TemporalConnection $settings,
    ) {
    }

    public function pollOnce(): PollWorkflowTaskQueueResponse
    {
        $req = new PollWorkflowTaskQueueRequest();
        $req->setNamespace($this->settings->namespace);
        $req->setTaskQueue(new TaskQueue(['name' => $this->settings->journalTaskQueue]));
        $req->setIdentity($this->settings->identity);
        $call = $this->client->PollWorkflowTaskQueue($req);
        $resp = GrpcUnary::wait($call);
        if (!$resp instanceof PollWorkflowTaskQueueResponse) {
            throw new \RuntimeException('Unexpected PollWorkflowTaskQueue response type.');
        }

        return $resp;
    }
}
