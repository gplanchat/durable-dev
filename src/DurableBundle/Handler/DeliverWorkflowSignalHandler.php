<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;

/**
 * Append {@see WorkflowSignalReceived} puis {@see WorkflowResumeDispatcher::dispatchResume()}.
 */
final class DeliverWorkflowSignalHandler
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
    ) {
    }

    public function __invoke(DeliverWorkflowSignalMessage $message): void
    {
        $this->eventStore->append(new WorkflowSignalReceived(
            $message->executionId,
            $message->signalName,
            $message->payload,
        ));
        $this->resumeDispatcher->dispatchResume($message->executionId);
    }
}
