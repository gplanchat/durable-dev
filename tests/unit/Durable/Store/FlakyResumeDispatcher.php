<?php

declare(strict_types=1);

namespace unit\Durable\Store;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Handler\ResumeWorkflowHandler;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;

/**
 * Runs every resume in the caller's process, as the memory backend does; the next announcement of an
 * awaited fact throws `$failOnce` instead, once.
 */
final class FlakyResumeDispatcher implements WorkflowResumeDispatcher
{
    public ?ResumeWorkflowHandler $handler = null;
    public ?\Throwable $failOnce = null;

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        ($this->handler)(new ResumeWorkflowMessage($executionId->toString(), $pendingUpdates));
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        if (null !== $this->failOnce) {
            $failure = $this->failOnce;
            $this->failOnce = null;

            throw $failure;
        }
    }

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        ($this->handler)(new ResumeWorkflowMessage($executionId->toString()));
    }
}
