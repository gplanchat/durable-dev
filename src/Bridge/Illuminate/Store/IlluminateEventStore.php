<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Store\PassFence;
use Gplanchat\Durable\Store\StoredTimestamp;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;

/**
 * Event journal on an Illuminate connection.
 *
 * Give it a connection of its own, not the application's default one (DUR054): the transactions
 * {@see claimPass()} and {@see appendFenced()} open would otherwise nest inside the application's,
 * and a business rollback would erase journal events.
 *
 * (De)serialization goes entirely through {@see EventDataMapper}: the rows have the same shape as
 * those of the DBAL bridge and as the records of the Temporal journal. This is not a writing
 * convention but a proven requirement — both bridges replay
 * {@see \Gplanchat\Durable\Testing\EventStoreConformanceTestCase}, whose fidelity case compares the
 * record read back with the record written, over the twenty-three types the mapper knows.
 *
 * ponytail: no `sequence` column — the auto-increment carries insertion order, as on the DBAL side.
 * Mutual exclusion between two concurrent resumes of the same execution lives upstream, in the
 * queue: on the Laravel side that is `WithoutOverlapping` or an atomic cache lock, and no storage
 * choice provides it.
 *
 * @see DUR030
 * @see DUR041
 */
final readonly class IlluminateEventStore implements FencedEventStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_events',
    ) {}

    public function append(Event $event): void
    {
        $this->schema->ensure();

        $this->connection->table($this->table)->insert($this->row($event));
    }

    public function claimPass(ExecutionId $executionId): PassFence
    {
        $this->schema->ensure();
        $id = $executionId->toString();
        $heads = $this->connection->table($this->schema->headsTable());

        // The first claim creates the row; two first claims racing each other leave one row.
        $heads->clone()->insertOrIgnore(['execution_id' => $id, 'epoch' => 0]);

        // The update locks the row until the claim commits: a fenced append waits for it (DUR053).
        $epoch = $this->connection->transaction(static function () use ($heads, $id): int {
            $heads->clone()->where('execution_id', $id)->increment('epoch');

            return (int) $heads->clone()->where('execution_id', $id)->value('epoch');
        });

        return new PassFence($id, $epoch);
    }

    public function appendFenced(Event $event, PassFence $fence): void
    {
        if (!$fence->fences()) {
            $this->append($event);

            return;
        }
        $this->schema->ensure();

        $inserted = 'sqlite' === $this->connection->getDriverName()
            ? $this->appendFencedInOneStatement($event, $fence)
            : $this->connection->transaction(fn(): bool => $this->appendFencedUnderSharedLock($event, $fence));

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
        $grammar = $this->connection->getQueryGrammar();

        try {
            return 1 === $this->connection->affectingStatement(
                \sprintf(
                    'insert into %s (execution_id, event_type, payload, recorded_at) select ?, ?, ?, ? where (select epoch from %s where execution_id = ?) = ?',
                    $grammar->wrapTable($this->table),
                    $grammar->wrapTable($this->schema->headsTable()),
                ),
                [...array_values($row), $fence->executionId, $fence->epoch],
            );
        } catch (QueryException $e) {
            // Only a newer claim supersedes the pass. Any other writer holding the database is a
            // transient wait, and the lock error goes up for the resume to be retried (#616).
            if (!str_contains($e->getMessage(), 'database is locked')) {
                throw $e;
            }
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
            $epoch = $this->connection->table($this->schema->headsTable())->where('execution_id', $executionId)->value('epoch');
        } catch (QueryException) {
            return null;
        }

        return null === $epoch ? 0 : (int) $epoch;
    }

    /** MySQL and PostgreSQL: the epoch is read under a shared lock that a claim's update must wait for. */
    private function appendFencedUnderSharedLock(Event $event, PassFence $fence): bool
    {
        $epoch = $this->connection->table($this->schema->headsTable())
            ->where('execution_id', $fence->executionId)
            ->sharedLock()
            ->value('epoch');
        if ((int) $epoch !== $fence->epoch) {
            return false;
        }
        $this->connection->table($this->table)->insert($this->row($event));

        return true;
    }

    /** @return array{execution_id: string, event_type: string, payload: string, recorded_at: string} */
    private function row(Event $event): array
    {
        $record = EventDataMapper::fromDomainEvent($event);

        return [
            'execution_id' => $record['execution_id'],
            'event_type' => $record['event_type'],
            'payload' => json_encode($record['payload'], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
            'recorded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
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

        // `cursor()` rather than `get()`: the stream is read once, without materializing a long
        // execution in memory. A second call starts from a fresh query, which conformance
        // explicitly requires.
        $rows = $this->connection->table($this->table)
            ->select(['event_type', 'payload', 'recorded_at'])
            ->where('execution_id', $executionId->toString())
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            yield [
                'event' => EventDataMapper::toDomainEvent([
                    'execution_id' => $executionId->toString(),
                    'event_type' => $row->event_type,
                    'payload' => $row->payload,
                ]),
                'recordedAt' => StoredTimestamp::toDateTime($row->recorded_at),
            ];
        }
    }

    public function countEventsInStream(ExecutionId $executionId): int
    {
        $this->schema->ensure();

        return $this->connection->table($this->table)
            ->where('execution_id', $executionId->toString())
            ->count();
    }
}
