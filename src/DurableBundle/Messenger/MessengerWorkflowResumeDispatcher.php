<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Messenger;

use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

final class MessengerWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly WorkflowMetadataStore $metadataStore,
    ) {
    }

    public function dispatchResume(string $executionId): void
    {
        $this->bus->dispatch(new Envelope(
            new ResumeWorkflowMessage($executionId),
            [new DispatchAfterCurrentBusStamp()],
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
    {
        $this->metadataStore->save($executionId, $workflowType, $payload);
        $this->bus->dispatch(new Envelope(
            new ResumeWorkflowMessage($executionId),
            [new DispatchAfterCurrentBusStamp()],
        ));
    }
}
