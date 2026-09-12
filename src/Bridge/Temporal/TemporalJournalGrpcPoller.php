<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal;

use Temporal\Api\Taskqueue\V1\TaskQueue;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueRequest;
use Temporal\Api\Workflowservice\V1\PollWorkflowTaskQueueResponse;

/**
 * Long-poll one workflow task from the journal task queue.
 */
final class TemporalJournalGrpcPoller
{
    public function __construct(
        private readonly WorkflowServiceClientInterface $client,
        private readonly TemporalConnection $settings,
    ) {}

    public function pollOnce(): PollWorkflowTaskQueueResponse
    {
        $req = new PollWorkflowTaskQueueRequest();
        $req->setNamespace($this->settings->namespace->name());
        $req->setTaskQueue(new TaskQueue(['name' => $this->settings->journalTaskQueue->name()]));
        $req->setIdentity($this->settings->identity);
        $resp = $this->client->PollWorkflowTaskQueue($req);

        return $resp;
    }
}
