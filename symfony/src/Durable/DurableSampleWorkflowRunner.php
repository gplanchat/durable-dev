<?php

declare(strict_types=1);

namespace App\Durable;

use Gplanchat\Bridge\Temporal\WorkflowClient;
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
 * Lance des workflows sample : envoi Messenger seul ({@see dispatchWorkflowRun}) ou attente du résultat
 * ({@see waitForWorkflowCompletion}, {@see runAndSettle}). Par défaut le dispatch est non bloquant ;
 * l'historique dans le profiler Web provient de l'event store pour les executionId collectés sur la requête.
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
        private readonly ?WorkflowClient $workflowClient = null,
    ) {
    }

    public function hasWorkflow(string $workflowType): bool
    {
        return $this->workflowRegistry->has($workflowType);
    }

    /**
     * Dispatche un nouveau run de workflow via {@see WorkflowResumeDispatcher::dispatchNewWorkflowRun}.
     */
    public function dispatchWorkflowRun(string $workflowType, array $payload, ?string $executionId = null): string
    {
        $executionId = $executionId ?? (string) Uuid::v4();
        $this->workflowResumeDispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $payload);

        return $executionId;
    }

    /**
     * Attend la complétion du workflow.
     *
     * - Backend **Temporal natif** (multi-processus) : sonde `GetWorkflowExecutionHistory` via
     *   {@see WorkflowClient::pollForCompletion()} — ne suppose pas de worker dans le même processus.
     * - Backend **in-memory** : vide les transports Messenger in-process via {@see DurableMessengerDrain}.
     *
     * @return mixed résultat du workflow
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
            throw new \RuntimeException('Drain Messenger incomplet (limite d\'itérations ou exécution non terminée).');
        }
        $result = WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId);
        if (null === $result) {
            throw new \RuntimeException('Aucun ExecutionCompleted dans le journal (échec ou drain incomplet).');
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
     * Pour un workflow qui commence par {@see WorkflowEnvironment::waitSignal()} : vide les transports
     * jusqu'à la suspension sans minuteur en attente, envoie le signal, puis attend la fin.
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
            throw new \RuntimeException('Drain incomplet avant envoi du signal (timeout ou exécution inactive).');
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
     * Comme {@see runAndSettleWithAutoSignal} mais pour {@see \Gplanchat\Durable\WorkflowEnvironment::waitUpdate()}.
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
        mixed $updateResult,
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
            throw new \RuntimeException('Drain incomplet avant envoi de l\'update (timeout ou exécution inactive).');
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
            $updateResult,
        ));
        $result = $this->waitForWorkflowCompletion($executionId);

        return ['executionId' => $executionId, 'result' => $result];
    }
}
