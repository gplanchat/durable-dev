<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Messenger;

use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

final class MessengerWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function dispatchResume(string $executionId): void
    {
        $this->bus->dispatch(new Envelope(
            new WorkflowRunMessage($executionId),
            [new DispatchAfterCurrentBusStamp()],
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
    {
        $this->bus->dispatch(new Envelope(
            new WorkflowRunMessage($executionId, $workflowType, $payload),
            [new DispatchAfterCurrentBusStamp()],
        ));
    }
}
