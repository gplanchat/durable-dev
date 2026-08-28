<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Laravel\Queue;

use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Job;

/**
 * Le port de transport des activités, sur la file de Laravel.
 *
 * Même adaptation que {@see \Gplanchat\Durable\Bundle\Transport\MessengerActivityTransport} :
 * `enqueue` pousse, `dequeue` dépile et acquitte. Ce qui change est le vocabulaire — `later()` au
 * lieu d'un `DelayStamp`, `pop()` au lieu d'un `ReceiverInterface`.
 *
 * **Le report devient celui de la file, puis disparaît du message.** C'est le contrat que le
 * transport en mémoire et celui de Messenger tiennent déjà : un `retryDelay` qui survivrait à la
 * mise en file serait attendu deux fois.
 *
 * En production personne n'appelle la moitié « pull » de ce port : `queue:work` pousse le job dans
 * `handle()`. Elle est implémentée quand même, parce qu'un drain synchrone — un test, une commande
 * qui vide la file à la main — a le droit d'exister, et qu'un `isEmpty()` qui mentirait ferait
 * conclure « plus rien à faire » à un appelant qui a encore du travail.
 */
final class LaravelActivityTransport implements ActivityTransportInterface
{
    private ?Job $pending = null;

    public function __construct(
        private readonly QueueFactory $queue,
        private readonly ?string $connection = null,
        private readonly ?string $queueName = null,
    ) {}

    public function enqueue(ActivityMessage $message): void
    {
        $delaySeconds = null !== $message->retryDelay ? $message->retryDelay->toSeconds() : 0.0;
        $job = new RunActivityJob($message->withoutRetryDelay());
        $connection = $this->queue->connection($this->connection);

        if ($delaySeconds > 0.0) {
            $connection->later((int) ceil($delaySeconds), $job, '', $this->queueName);

            return;
        }

        $connection->push($job, '', $this->queueName);
    }

    public function dequeue(): ?ActivityMessage
    {
        $job = $this->take();
        if (null === $job) {
            return null;
        }

        $message = self::messageOf($job);
        // Acquitter dans les deux cas : un job qui n'est pas le nôtre n'a rien à faire ici, et le
        // laisser en file le ferait repasser à chaque tour.
        $job->delete();

        return $message;
    }

    public function isEmpty(): bool
    {
        if (null !== $this->pending) {
            return false;
        }

        // Dépiler pour savoir, et **garder** ce qu'on a dépilé : un `isEmpty()` qui jette le job
        // qu'il vient de sortir répond juste une fois et perd du travail à chaque appel.
        $this->pending = $this->queue->connection($this->connection)->pop($this->queueName);

        return null === $this->pending;
    }

    /** La file porte elle-même le report : rien à attendre côté PHP. */
    public function nextDueAt(): ?float
    {
        return $this->isEmpty() ? null : microtime(true);
    }

    /** Best effort, et Laravel ne le permet pas : un job en file ne se retire pas par son contenu. */
    public function removePendingFor(string $executionId, string $activityId): bool
    {
        return false;
    }

    private function take(): ?Job
    {
        if (null !== $this->pending) {
            $job = $this->pending;
            $this->pending = null;

            return $job;
        }

        return $this->queue->connection($this->connection)->pop($this->queueName);
    }

    private static function messageOf(Job $job): ?ActivityMessage
    {
        /** @var array{data?: array{command?: string}} $payload */
        $payload = $job->payload();
        $serialized = $payload['data']['command'] ?? null;
        if (!\is_string($serialized)) {
            return null;
        }

        $command = @unserialize($serialized);

        return $command instanceof RunActivityJob ? $command->message : null;
    }
}
