<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Store\PassFence;
use Gplanchat\Durable\Store\StoredTimestamp;

/**
 * Event journal persisted in SQL — the durable counterpart of
 * {@see \Gplanchat\Durable\Store\InMemoryEventStore}.
 *
 * (De)serialization goes entirely through {@see EventDataMapper}: the rows have the same shape as
 * the records of the Temporal journal, which the mapper already documents.
 *
 * ponytail: no `sequence` column — the auto-increment carries the insertion order.
 * Mutual exclusion between two concurrent resumes of the same execution sits upstream, in
 * {@see \Gplanchat\Bridge\Dbal\Messenger\SingleResumeLockMiddleware}; without it, two workers
 * would replay the same execution and duplicate its commands.
 *
 * @see DUR030
 */
final readonly class DbalEventStore implements FencedEventStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_events',
    ) {}

    public function append(Event $event): void
    {
        $this->schema->ensure();

        $this->connection->insert($this->table, $this->row($event), ['recorded_at' => 'datetime_immutable']);
    }

    public function claimPass(ExecutionId $executionId): PassFence
    {
        $this->schema->ensure();
        $heads = $this->schema->headsTable();

        // The first claim creates the row; two first claims racing each other leave one row. An
        // insert that ignores the duplicate rather than a caught violation: on PostgreSQL, a
        // violation aborts the transaction the pass may run in.
        $platform = $this->connection->getDatabasePlatform();
        $this->connection->executeStatement(match (true) {
            $platform instanceof SQLitePlatform => \sprintf('INSERT OR IGNORE INTO %s (execution_id, epoch) VALUES (?, 0)', $heads),
            $platform instanceof AbstractMySQLPlatform => \sprintf('INSERT IGNORE INTO %s (execution_id, epoch) VALUES (?, 0)', $heads),
            default => \sprintf('INSERT INTO %s (execution_id, epoch) VALUES (?, 0) ON CONFLICT (execution_id) DO NOTHING', $heads),
        }, [$executionId->toString()]);

        // The update locks the row until the claim commits: a fenced append waits for it (DUR053).
        $epoch = $this->connection->transactional(function (Connection $connection) use ($heads, $executionId): int {
            $connection->executeStatement(\sprintf('UPDATE %s SET epoch = epoch + 1 WHERE execution_id = ?', $heads), [$executionId->toString()]);

            return (int) $connection->fetchOne(\sprintf('SELECT epoch FROM %s WHERE execution_id = ?', $heads), [$executionId->toString()]);
        });

        return new PassFence($executionId->toString(), $epoch);
    }

    public function appendFenced(Event $event, PassFence $fence): void
    {
        if (!$fence->fences()) {
            $this->append($event);

            return;
        }
        $this->schema->ensure();

        $inserted = $this->connection->getDatabasePlatform() instanceof SQLitePlatform
            ? $this->appendFencedInOneStatement($event, $fence)
            : $this->connection->transactional(fn(): bool => $this->appendFencedUnderSharedLock($event, $fence));

        if (!$inserted) {
            throw SupersededPassException::for($fence);
        }
    }

    /**
     * SQLite has no row locks but admits one writer at a time: a single conditional insert cannot
     * straddle a claim (DUR053). A busy database is a lost race only when a newer claim moved the
     * epoch; otherwise it is a wait like any other.
     */
    private function appendFencedInOneStatement(Event $event, PassFence $fence): bool
    {
        $row = $this->row($event);

        try {
            return 1 === $this->connection->executeStatement(
                \sprintf(
                    'INSERT INTO %s (execution_id, event_type, payload, recorded_at) SELECT ?, ?, ?, ? WHERE (SELECT epoch FROM %s WHERE execution_id = ?) = ?',
                    $this->table,
                    $this->schema->headsTable(),
                ),
                [$row['execution_id'], $row['event_type'], $row['payload'], $row['recorded_at'], $fence->executionId, $fence->epoch],
                [3 => 'datetime_immutable'],
            );
        } catch (LockWaitTimeoutException $e) {
            // Only a newer claim supersedes the pass. Any other writer holding the database is a
            // transient wait, and the lock error goes up for the resume to be retried (#616).
            $current = $this->currentEpoch($fence->executionId);
            if (null === $current || $current === $fence->epoch) {
                throw $e;
            }

            throw new SupersededPassException(SupersededPassException::for($fence)->getMessage(), 0, $e);
        }
    }

    /** Null when even the read is refused: the caller then keeps the original error. */
    private function currentEpoch(string $executionId): ?int
    {
        try {
            $epoch = $this->connection->fetchOne(\sprintf('SELECT epoch FROM %s WHERE execution_id = ?', $this->schema->headsTable()), [$executionId]);
        } catch (LockWaitTimeoutException) {
            return null;
        }

        return false === $epoch ? 0 : (int) $epoch;
    }

    /** MySQL and PostgreSQL: the epoch is read under a shared lock that a claim's update must wait for. */
    private function appendFencedUnderSharedLock(Event $event, PassFence $fence): bool
    {
        $lock = $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'LOCK IN SHARE MODE' : 'FOR SHARE';
        $epoch = $this->connection->fetchOne(
            \sprintf('SELECT epoch FROM %s WHERE execution_id = ? %s', $this->schema->headsTable(), $lock),
            [$fence->executionId],
        );
        if ((int) $epoch !== $fence->epoch) {
            return false;
        }
        $this->connection->insert($this->table, $this->row($event), ['recorded_at' => 'datetime_immutable']);

        return true;
    }

    /** @return array{execution_id: string, event_type: string, payload: string, recorded_at: \DateTimeImmutable} */
    private function row(Event $event): array
    {
        $record = EventDataMapper::fromDomainEvent($event);

        return [
            'execution_id' => $record['execution_id'],
            'event_type' => $record['event_type'],
            'payload' => json_encode($record['payload'], \JSON_THROW_ON_ERROR),
            'recorded_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
    }

    public function readStream(ExecutionId $executionId): iterable
    {
        foreach ($this->readStreamWithRecordedAt($executionId) as $entry) {
            yield $entry['event'];
        }
    }

    public function readStreamWithRecordedAt(ExecutionId $executionId): iterable
    {
        $this->schema->ensure();

        $rows = $this->connection->executeQuery(
            \sprintf('SELECT event_type, payload, recorded_at FROM %s WHERE execution_id = ? ORDER BY id ASC', $this->table),
            [$executionId->toString()],
        );

        foreach ($rows->iterateAssociative() as $row) {
            yield [
                'event' => EventDataMapper::toDomainEvent([
                    'execution_id' => $executionId->toString(),
                    'event_type' => $row['event_type'],
                    'payload' => $row['payload'],
                ]),
                'recordedAt' => StoredTimestamp::toDateTime($row['recorded_at']),
            ];
        }
    }

    public function countEventsInStream(ExecutionId $executionId): int
    {
        $this->schema->ensure();

        return (int) $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE execution_id = ?', $this->table),
            [$executionId->toString()],
        );
    }
}
