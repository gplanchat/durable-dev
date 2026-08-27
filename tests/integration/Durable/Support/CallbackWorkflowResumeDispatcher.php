<?php

declare(strict_types=1);

namespace integration\Durable\Support;

use Gplanchat\Durable\Port\WorkflowResumeDispatcher;

/**
 * Dispatcher de test : callbacks explicites pour simuler la reprise sans Messenger.
 */
final class CallbackWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private readonly \Closure $onResume,
        private readonly ?\Closure $onNew = null,
    ) {}

    /**
     * @param array<string, mixed> $pendingUpdates
     */
    public function dispatchResume(string $executionId, array $pendingUpdates = []): void
    {
        ($this->onResume)($executionId, $pendingUpdates);
    }

    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
    {
        if (null !== $this->onNew) {
            ($this->onNew)($executionId, $workflowType, $payload);
        }
    }
}
