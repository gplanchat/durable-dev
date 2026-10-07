<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Bundle\Messenger;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;

final readonly class MessengerWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly WorkflowMetadataStore $metadataStore,
        /** Where a resume is routed, to tell a `sync` route apart (DUR050); absent, none is assumed. */
        private readonly ?SendersLocatorInterface $senders = null,
    ) {}

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->bus->dispatch(new Envelope(
            new ResumeWorkflowMessage($executionId->toString(), $pendingUpdates),
            [new DispatchAfterCurrentBusStamp()],
        ));
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        $message = new ResumeWorkflowMessage($executionId->toString(), [], $fact);
        // No DispatchAfterCurrentBusStamp: held until the activity handler returns, it would leave
        // after the append, and the worker could die before it left.
        if ($this->routedAsynchronously($message)) {
            $this->bus->dispatch($message);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        // A caller passing `::class` gets the alias: the name the journal, the dashboard and the
        // diagnose command all show (#258).
        $workflowType = (new WorkflowDefinitionLoader())->aliasForTemporalInterop($workflowType);
        // Insert-only (#918): a row that exists is left as it is. Rewriting it would set `completed`
        // back to false and reopen a run that finished since the caller read it. The first send of a
        // run finds no row, and a run without one cannot complete.
        if (null === $this->metadataStore->get($executionId)) {
            $this->metadataStore->save($executionId, $workflowType, $payload);
        }
        $this->bus->dispatch(new Envelope(
            new ResumeWorkflowMessage($executionId->toString()),
            [new DispatchAfterCurrentBusStamp(), new NewWorkflowRunStamp($workflowType)],
        ));
    }

    /**
     * A `sync` route, or none, handles the resume inline: sent before the append, it would always
     * run first. The resume sent after the append does the work there.
     */
    private function routedAsynchronously(ResumeWorkflowMessage $message): bool
    {
        foreach ($this->senders?->getSenders(new Envelope($message)) ?? [] as $sender) {
            if (!$sender instanceof SyncTransport) {
                return true;
            }
        }

        return null === $this->senders;
    }
}
