<?php

declare(strict_types=1);

namespace integration\Durable\Crash;

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

/**
 * The activity queue and the resume queue of {@see ResumeDispatchedFirstCrashTest}, two tables in the
 * SQLite file that holds the journal, so that every process of the bench sees them.
 *
 * A message is taken with {@see take()} and removed with {@see ack()} once its process is done with
 * it: a process killed in between leaves it for the next one, as a real transport leaves an
 * unacknowledged message. Test support, not a transport: no locking, one consumer at a time.
 */
final class SqliteTestQueues implements ActivityTransportInterface, WorkflowResumeDispatcher, WorkflowTimerDispatcher
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WorkflowMetadataStore $metadata,
    ) {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS test_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT NOT NULL, body BLOB NOT NULL)');
    }

    public function enqueue(ActivityMessage $message): void
    {
        $this->push('activities', $message);
    }

    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void
    {
        $this->push('resumes', new ResumeWorkflowMessage($executionId->toString(), $pendingUpdates));
    }

    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void
    {
        $this->push('resumes', new ResumeWorkflowMessage($executionId->toString(), [], $fact));
    }

    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->metadata->save($executionId, $workflowType, $payload);
        $this->push('resumes', new ResumeWorkflowMessage($executionId->toString()));
    }

    /** The bench fires timers by hand: the delay is not honoured, the `timer` step comes later. */
    public function dispatchTimerFire(ExecutionId $executionId, int $delayMs = 0): void
    {
        $this->push('timers', new FireWorkflowTimersMessage($executionId->toString()));
    }

    /**
     * @return array{int, object}|null the oldest message of the queue and its id, left in place
     */
    public function take(string $queue): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id, body FROM test_queue WHERE queue = ? ORDER BY id LIMIT 1', [$queue]);
        if (false === $row) {
            return null;
        }
        $message = unserialize((string) $row['body']);
        \assert(\is_object($message));

        return [(int) $row['id'], $message];
    }

    public function ack(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM test_queue WHERE id = ?', [$id]);
    }

    public function count(string $queue): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM test_queue WHERE queue = ?', [$queue]);
    }

    public function dequeue(): ?ActivityMessage
    {
        throw new \LogicException('the bench takes and acknowledges its messages itself');
    }

    public function isEmpty(): bool
    {
        return 0 === $this->count('activities');
    }

    public function nextDueAt(): ?float
    {
        return null;
    }

    public function removePendingFor(ExecutionId $executionId, string $activityId): bool
    {
        return false;
    }

    private function push(string $queue, object $message): void
    {
        $this->connection->executeStatement('INSERT INTO test_queue (queue, body) VALUES (?, ?)', [$queue, serialize($message)]);
    }
}
