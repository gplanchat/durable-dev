<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Handler;

use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;

/**
 * Hands the update over to the workflow's next pass, which will process it and record its outcome.
 *
 * This handler no longer writes anything itself. It could not honestly do so: the outcome of an
 * update is the return value of its handler, which only a pass of the workflow produces — that is
 * what the Temporal worker does, which accepts *and* answers on the same task. Writing a result
 * supplied by the caller here was the inverse of the model.
 *
 * The pass is the one of {@see ResumeWorkflowHandler}: nothing of the lifecycle of an execution —
 * suspension, continue-as-new, closure, bubbling up to the parent — is rewritten here.
 */
final class DeliverWorkflowUpdateHandler
{
    public function __construct(
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
    ) {}

    public function __invoke(DeliverWorkflowUpdateMessage $message): void
    {
        $this->resumeDispatcher->dispatchResume($message->executionId, [[
            'name' => $message->updateName,
            'arguments' => $message->arguments,
        ]]);
    }
}
