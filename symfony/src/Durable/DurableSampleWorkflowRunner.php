<?php

declare(strict_types=1);

namespace App\Durable;

use Gplanchat\Durable\Query\WorkflowQueryEvaluator;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\WorkflowRunMessage;
use Gplanchat\Durable\WorkflowRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lance des workflows sample : envoi Messenger seul ({@see dispatchWorkflowRun}) ou attente du résultat
 * ({@see waitForWorkflowCompletion}, {@see runAndSettle}). Par défaut le dispatch est non bloquant ;
 * l’historique dans le profiler Web provient de l’event store pour les executionId collectés sur la requête.
 */
final class DurableSampleWorkflowRunner
{
    public function __construct(
        private readonly WorkflowRegistry $workflowRegistry,
        private readonly MessageBusInterface $messageBus,
        private readonly EventStoreInterface $eventStore,
        private readonly WorkflowMetadataStore $workflowMetadataStore,
        #[Autowire(service: 'messenger.receiver_locator')]
        private readonly ContainerInterface $receiverLocator,
    ) {
    }

    public function hasWorkflow(string $workflowType): bool
    {
        return $this->workflowRegistry->has($workflowType);
    }

    /**
     * Envoie un {@see WorkflowRunMessage} sur le bus sans attendre la fin (fire-and-forget).
     */
    public function dispatchWorkflowRun(string $workflowType, array $payload, ?string $executionId = null): string
    {
        $executionId = $executionId ?? (string) Uuid::v4();
        $this->messageBus->dispatch(new WorkflowRunMessage($executionId, $workflowType, $payload));

        return $executionId;
    }

    /**
     * Vide les transports Messenger jusqu’à {@see ExecutionCompleted} pour cet executionId.
     *
     * @return mixed résultat du workflow
     */
    public function waitForWorkflowCompletion(string $executionId): mixed
    {
        if (!DurableMessengerDrain::drainUntilWorkflowSettled(
            $this->eventStore,
            $this->workflowMetadataStore,
            $this->messageBus,
            $this->receiverLocator,
            $executionId,
        )) {
            throw new \RuntimeException('Drain Messenger incomplet (limite d’itérations ou exécution non terminée).');
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
        $this->messageBus->dispatch(new WorkflowRunMessage($executionId, $workflowType, $payload));
        $result = $this->waitForWorkflowCompletion($executionId);

        return ['executionId' => $executionId, 'result' => $result];
    }

}
