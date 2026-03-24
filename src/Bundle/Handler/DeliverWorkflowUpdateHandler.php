<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;

/**
 * Append {@see WorkflowUpdateHandled} puis {@see WorkflowResumeDispatcher::dispatchResume()}.
 */
final class DeliverWorkflowUpdateHandler
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
    ) {
    }

    public function __invoke(DeliverWorkflowUpdateMessage $message): void
    {
        $this->eventStore->append(new WorkflowUpdateHandled(
            $message->executionId,
            $message->updateName,
            $message->arguments,
            $message->result,
        ));
        $this->resumeDispatcher->dispatchResume($message->executionId);
    }
}
