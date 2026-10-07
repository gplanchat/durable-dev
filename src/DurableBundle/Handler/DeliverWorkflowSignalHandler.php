<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;

/**
 * Journals a delivered signal, in DUR052's order: a resume naming it, the append, a plain resume.
 *
 * The signal carries its delivery's request id, so a redelivered message finds it already in the
 * journal: it appends nothing more, and still resumes, since the crash that caused the redelivery
 * may have come before the resume.
 */
final readonly class DeliverWorkflowSignalHandler
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
    ) {}

    public function __invoke(DeliverWorkflowSignalMessage $message): void
    {
        $id = ExecutionId::fromString($message->executionId);
        $signal = AwaitedFact::signal($message->requestId);
        if (!$signal->isJournalledIn($this->eventStore, $id)) {
            $this->resumeDispatcher->dispatchResumeAwaiting($id, $signal);
            $this->eventStore->append(new WorkflowSignalReceived(
                $id,
                $message->signalName,
                $message->payload,
                $message->requestId,
            ));
        }
        $this->resumeDispatcher->dispatchResume($id);
    }
}
