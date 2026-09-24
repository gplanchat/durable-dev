<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Temporal\Messenger;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;

/**
 * On Temporal native the update goes to the cluster, whose worker accepts and answers it on the
 * same workflow task. The result is dropped, as on the journal side: a message has nobody to
 * return it to.
 */
final class DeliverWorkflowUpdateToTemporalHandler
{
    public function __construct(
        private readonly WorkflowClientInterface $client,
    ) {}

    public function __invoke(DeliverWorkflowUpdateMessage $message): void
    {
        $this->client->update($this->client->workflowId($message->executionId), $message->updateName, $message->arguments, $message->updateId);
    }
}
