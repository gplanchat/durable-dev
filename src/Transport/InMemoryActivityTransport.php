<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

final class InMemoryActivityTransport implements ActivityTransportInterface
{
    private \SplQueue $queue;

    public function __construct()
    {
        $this->queue = new \SplQueue();
    }

    public function enqueue(ActivityMessage $message): void
    {
        $this->queue->enqueue($message);
    }

    public function dequeue(): ?ActivityMessage
    {
        if ($this->queue->isEmpty()) {
            return null;
        }

        return $this->queue->dequeue();
    }

    /**
     * Premier message sans le retirer (tests / orchestration distribuée).
     */
    public function peek(): ?ActivityMessage
    {
        if ($this->queue->isEmpty()) {
            return null;
        }
        $this->queue->rewind();

        /* @var ActivityMessage */
        return $this->queue->current();
    }

    public function isEmpty(): bool
    {
        return $this->queue->isEmpty();
    }

    public function removePendingFor(string $executionId, string $activityId): bool
    {
        $removed = false;
        $buffer = [];
        while (!$this->queue->isEmpty()) {
            $message = $this->queue->dequeue();
            if (!$removed && $message->executionId === $executionId && $message->activityId === $activityId) {
                $removed = true;

                continue;
            }
            $buffer[] = $message;
        }
        foreach ($buffer as $message) {
            $this->queue->enqueue($message);
        }

        return $removed;
    }

    /**
     * Nombre de messages encore en file (FIFO).
     */
    public function pendingCount(): int
    {
        return $this->queue->count();
    }

    /**
     * Lecture non destructive de la file : nom d'activité et payload métier par message,
     * dans l'ordre de traitement (utile pour les tests d'exécution distribuée).
     *
     * @return list<array{name: string, payload: array}>
     */
    public function inspectPendingActivities(): array
    {
        $snapshot = [];
        $buffer = [];
        while (!$this->queue->isEmpty()) {
            $message = $this->queue->dequeue();
            $buffer[] = $message;
            $snapshot[] = [
                'name' => $message->activityName,
                'payload' => $message->payload,
            ];
        }
        foreach ($buffer as $message) {
            $this->queue->enqueue($message);
        }

        return $snapshot;
    }
}
