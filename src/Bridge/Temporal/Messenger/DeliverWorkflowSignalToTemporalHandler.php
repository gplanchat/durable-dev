<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;

/**
 * On Temporal native the cluster is the journal: the signal goes to it, and Temporal schedules the
 * workflow task that delivers it. The journal-side handler would append to a local store nobody
 * replays and ask for a resume nothing performs.
 */
final class DeliverWorkflowSignalToTemporalHandler
{
    public function __construct(
        private readonly WorkflowClientInterface $client,
    ) {}

    public function __invoke(DeliverWorkflowSignalMessage $message): void
    {
        $this->client->signal($this->client->workflowId($message->executionId), $message->signalName, $message->payload, $message->requestId);
    }
}
