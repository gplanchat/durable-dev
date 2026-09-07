<?php

declare(strict_types=1);

namespace App\Durable;

use Gplanchat\Bridge\Temporal\WorkflowClientInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\DeliverWorkflowSignalMessage;
use Gplanchat\Durable\Transport\DeliverWorkflowUpdateMessage;
use Gplanchat\Durable\WorkflowRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Runs sample workflows: Messenger dispatch alone ({@see dispatchWorkflowRun}) or waiting for the result
 * ({@see waitForWorkflowCompletion}, {@see runAndSettle}). By default the dispatch is non-blocking;
 * the history in the Web profiler comes from the event store for the executionIds collected on the request.
 */
final class DurableSampleWorkflowRunner
{
    public function __construct(
        private readonly WorkflowRegistry $workflowRegistry,
        private readonly WorkflowResumeDispatcher $workflowResumeDispatcher,
        private readonly MessageBusInterface $messageBus,
        private readonly EventStoreInterface $eventStore,
        private readonly WorkflowMetadataStore $workflowMetadataStore,
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ContainerInterface $receiverLocator,
        private readonly ?WorkflowClientInterface $workflowClient = null,
    ) {
    }

    public function hasWorkflow(string $workflowType): bool
    {
        return $this->workflowRegistry->has($workflowType);
    }

    /**
     * Dispatches a new workflow run through {@see WorkflowResumeDispatcher::dispatchNewWorkflowRun}.
     */
    public function dispatchWorkflowRun(string $workflowType, array $payload, ?string $executionId = null): string
    {
        $executionId = $executionId ?? (string) Uuid::v4();
        $this->workflowResumeDispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $payload);

        return $executionId;
    }

    /**
     * Waits for the workflow to complete.
     *
     * - **Native Temporal** backend (multi-process): probes `GetWorkflowExecutionHistory` through
     *   {@see WorkflowClient::pollForCompletion()} — does not assume a worker in the same process.
     * - **In-memory** backend: drains the in-process Messenger transports through {@see DurableMessengerDrain}.
     *
     * @return mixed the workflow result
     */
    public function waitForWorkflowCompletion(string $executionId): mixed
    {
        if (null !== $this->workflowClient) {
            return $this->workflowClient->pollForCompletion($executionId);
        }

        if (!DurableMessengerDrain::drainUntilWorkflowSettled(
            $this->eventStore,
            $this->workflowMetadataStore,
            $this->messageBus,
            $this->receiverLocator,
            $executionId,
        )) {
            throw new \RuntimeException('Incomplete Messenger drain (iteration limit, or the execution did not finish).');
        }
        $result = WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId);
        if (null === $result) {
            throw new \RuntimeException('No ExecutionCompleted in the journal (a failure, or an incomplete drain).');
        }

        return $result;
    }

    /**
     * @return array{executionId: string, result: mixed}
     */
    public function runAndSettle(string $workflowType, array $payload, ?string $executionId = null): array
    {
        $executionId = $executionId ?? (string) Uuid::v4();
        $this->workflowResumeDispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $payload);
        $result = $this->waitForWorkflowCompletion($executionId);

        return ['executionId' => $executionId, 'result' => $result];
    }

    /**
     * For a workflow that waits on a signal ({@see WorkflowEnvironment::onSignal()}): drains the transports
     * until it suspends with no pending timer, sends the signal, then waits for the end.
     *
     * @param array<string, mixed> $signalPayload
     *
     * @return array{executionId: string, result: mixed}
     */
    public function runAndSettleWithAutoSignal(
        string $workflowType,
        array $payload,
        string $signalName,
        array $signalPayload,
        ?string $executionId = null,
    ): array {
        $executionId = $executionId ?? (string) Uuid::v4();
        $this->workflowResumeDispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $payload);

        $phase = DurableMessengerDrain::drainUntilCompleteOrSignalWait(
            $this->eventStore,
            $this->workflowMetadataStore,
            $this->messageBus,
            $this->receiverLocator,
            $executionId,
        );

        if ('timeout' === $phase) {
            throw new \RuntimeException('Incomplete drain before the signal was sent (timeout, or the execution is inactive).');
        }

        if ('complete' === $phase) {
            $result = WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId);
            if (null === $result) {
                throw new \RuntimeException('Phase complete sans ExecutionCompleted.');
            }

            return ['executionId' => $executionId, 'result' => $result];
        }

        $this->messageBus->dispatch(new DeliverWorkflowSignalMessage($executionId, $signalName, $signalPayload));
        $result = $this->waitForWorkflowCompletion($executionId);

        return ['executionId' => $executionId, 'result' => $result];
    }

    /**
     * Like {@see runAndSettleWithAutoSignal} but for an update ({@see \Gplanchat\Durable\WorkflowEnvironment::onUpdate()}).
     *
     * @param array<string, mixed> $updateArguments
     *
     * @return array{executionId: string, result: mixed}
     */
    public function runAndSettleWithAutoUpdate(
        string $workflowType,
        array $payload,
        string $updateName,
        array $updateArguments,
        ?string $executionId = null,
    ): array {
        $executionId = $executionId ?? (string) Uuid::v4();
        $this->workflowResumeDispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $payload);

        $phase = DurableMessengerDrain::drainUntilCompleteOrSignalWait(
            $this->eventStore,
            $this->workflowMetadataStore,
            $this->messageBus,
            $this->receiverLocator,
            $executionId,
        );

        if ('timeout' === $phase) {
            throw new \RuntimeException('Incomplete drain before the update was sent (timeout, or the execution is inactive).');
        }

        if ('complete' === $phase) {
            $result = WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId);
            if (null === $result) {
                throw new \RuntimeException('Phase complete sans ExecutionCompleted.');
            }

            return ['executionId' => $executionId, 'result' => $result];
        }

        $this->messageBus->dispatch(new DeliverWorkflowUpdateMessage(
            $executionId,
            $updateName,
            $updateArguments,
        ));
        $result = $this->waitForWorkflowCompletion($executionId);

        return ['executionId' => $executionId, 'result' => $result];
    }
}
