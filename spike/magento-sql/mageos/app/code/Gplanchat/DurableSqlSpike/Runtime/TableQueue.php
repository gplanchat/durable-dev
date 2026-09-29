<?php

declare(strict_types=1);

namespace Gplanchat\DurableSqlSpike\Runtime;

use Doctrine\DBAL\Connection;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowTimerDispatcher;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\FireWorkflowTimersMessage;
use Gplanchat\Durable\Transport\ResumeWorkflowMessage;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * Q3: resumes, timers and activities on one table of the journal's own connection.
 *
 * A message is leased, not deleted, when a worker takes it: a worker killed mid-message leaves it
 * to reappear once the lease runs out. That is at-least-once, like MysqlMq, and the journal (the
 * attempt claim, the DUR053 fence) is what makes the second delivery harmless.
 *
 * ponytail: MySQL 8 only (SKIP LOCKED), serialize() bodies, one table for every kind. The real
 * adapter would add PostgreSQL/SQLite and join DurableSchema.
 */
final class TableQueue implements ActivityTransportInterface, WorkflowResumeDispatcher, WorkflowTimerDispatcher
{
    public const LEASE_SECONDS = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly WorkflowMetadataStore $metadata,
        private readonly string $table = 'durable_queue',
    ) {}

    public function setup(): void
    {
        $this->connection->executeStatement("CREATE TABLE IF NOT EXISTS {$this->table} (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(16) NOT NULL, body LONGTEXT NOT NULL,
            available_at DOUBLE NOT NULL, deliveries INT NOT NULL DEFAULT 0, INDEX (available_at))");
    }

    public function push(object $message, float $delaySeconds = 0.0): void
    {
        $this->connection->insert($this->table, [
            'kind' => match (true) { $message instanceof ActivityMessage => 'activity', $message instanceof FireWorkflowTimersMessage => 'timer', default => 'resume' },
            'body' => serialize($message),
            'available_at' => microtime(true) + $delaySeconds,
        ]);
    }

    /** @return array{id: int, message: object, deliveries: int}|null */
    public function take(): ?array
    {
        return $this->connection->transactional(function (Connection $c): ?array {
            $row = $c->fetchAssociative("SELECT id, body, deliveries FROM {$this->table} WHERE available_at <= ? ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED", [microtime(true)]);
            if (false === $row) {
                return null;
            }
            $c->executeStatement("UPDATE {$this->table} SET available_at = ?, deliveries = deliveries + 1 WHERE id = ?", [microtime(true) + self::LEASE_SECONDS, $row['id']]);

            return ['id' => (int) $row['id'], 'message' => unserialize($row['body']), 'deliveries' => (int) $row['deliveries'] + 1];
        });
    }

    public function ack(int $id): void
    {
        $this->connection->delete($this->table, ['id' => $id]);
    }

    public function count(): int
    {
        return (int) $this->connection->fetchOne("SELECT COUNT(*) FROM {$this->table}");
    }

    // ActivityTransportInterface: the worker loop takes messages itself; these serve the core.
    public function enqueue(ActivityMessage $message): void
    {
        $delay = null !== $message->retryDelay ? $message->retryDelay->toSeconds() : 0.0;
        $this->push($message->withoutRetryDelay(), $delay);
    }

    public function dequeue(): ?ActivityMessage
    {
        return null; // Distributed: nothing drains activities in the caller's process.
    }

    public function isEmpty(): bool
    {
        return true;
    }

    public function nextDueAt(): ?float
    {
        return null;
    }

    public function removePendingFor(string $executionId, string $activityId): bool
    {
        return false;
    }

    // WorkflowResumeDispatcher, modelled on LaravelWorkflowResumeDispatcher.
    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->push(new ResumeWorkflowMessage($executionId->toString(), $pendingUpdates));
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        $this->push(new ResumeWorkflowMessage($executionId->toString(), [], $fact));
    }

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->metadata->save($executionId, (new WorkflowDefinitionLoader())->aliasForTemporalInterop($workflowType), $payload);
        $this->push(new ResumeWorkflowMessage($executionId->toString()));
    }

    // WorkflowTimerDispatcher. Not a delayed resume, as LaravelWorkflowTimerDispatcher does: in
    // distributed mode a resume never fires a due timer, only FireWorkflowTimersHandler does. The
    // first run of this spike copied Laravel and spun 1146 passes on a due timer.
    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void
    {
        $this->push(new FireWorkflowTimersMessage($executionId), $delayMs / 1000);
    }
}
