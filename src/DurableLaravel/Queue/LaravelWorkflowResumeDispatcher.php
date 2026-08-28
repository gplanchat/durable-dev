<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Illuminate\Contracts\Queue\Factory as QueueFactory;

/**
 * Le port de reprise, sur la file de Laravel.
 *
 * Même forme que {@see \Gplanchat\Durable\Bundle\Messenger\MessengerWorkflowResumeDispatcher} : une
 * reprise est un message, un nouveau run enregistre d'abord ses métadonnées puis en devient une.
 *
 * **Ce que le pendant Symfony obtient d'un `DispatchAfterCurrentBusStamp`, celui-ci l'obtient de la
 * file elle-même** — à une condition, et c'est pourquoi le provider refuse la connexion `sync` au
 * démarrage : sur `sync`, `push()` exécute le job sur place, et une reprise qui en dispatche une
 * autre récurserait dans le même processus jusqu'à la pile.
 */
final class LaravelWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function __construct(
        private readonly QueueFactory $queue,
        private readonly WorkflowMetadataStore $metadataStore,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
    ) {}

    public function dispatchResume(string $executionId, array $pendingUpdates = []): void
    {
        $this->push(new ResumeWorkflowMessage($executionId, $pendingUpdates));
    }

    /** @param array<string, mixed> $payload */
    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
    {
        // Les métadonnées d'abord : une reprise qui arrive avant elles ne saurait pas quoi rejouer.
        $this->metadataStore->save($executionId, $workflowType, $payload);
        $this->push(new ResumeWorkflowMessage($executionId));
    }

    private function push(ResumeWorkflowMessage $message): void
    {
        $this->queue->connection($this->connection)->push(new ResumeWorkflowJob($message), '', $this->queueName);
    }
}
