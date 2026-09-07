<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Illuminate\Store;

use Gplanchat\Bridge\Illuminate\Schema\DurableSchema;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\EventStoreInterface;
use Illuminate\Database\Connection;

/**
 * Event journal on the connection Laravel already owns.
 *
 * This is the whole point of the bridge, and not merely idiomatic convenience: DUR030 sells durable
 * execution on **one** database without a cluster, which only pays off if the journal append and
 * the business write fall inside the same transaction. An activity writing through Eloquent while a
 * Doctrine journal writes through a second PDO is two transactional scopes: the process dies
 * between the two, replay replays the activity, and the guarantee being advertised never existed.
 * Here the store sits on `DB::connection()`, so `DB::transaction()` closes over both.
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
final class IlluminateEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DurableSchema $schema,
        private readonly string $table = 'durable_events',
    ) {}

    public function append(Event $event): void
    {
        $this->schema->ensure();

        $record = EventDataMapper::fromDomainEvent($event);

        $this->connection->table($this->table)->insert([
            'execution_id' => $record['execution_id'],
            'event_type' => $record['event_type'],
            'payload' => json_encode($record['payload'], \JSON_THROW_ON_ERROR),
            'recorded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format('Y-m-d H:i:s'),
        ]);
    }

    public function readStream(string $executionId): iterable
    {
        foreach ($this->readStreamWithRecordedAt($executionId) as $entry) {
            yield $entry['event'];
        }
    }

    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        $this->schema->ensure();

        // `cursor()` rather than `get()`: the stream is read once, without materializing a long
        // execution in memory. A second call starts from a fresh query, which conformance
        // explicitly requires.
        $rows = $this->connection->table($this->table)
            ->select(['event_type', 'payload', 'recorded_at'])
            ->where('execution_id', $executionId)
            ->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            yield [
                'event' => EventDataMapper::toDomainEvent([
                    'execution_id' => $executionId,
                    'event_type' => $row->event_type,
                    'payload' => $row->payload,
                ]),
                'recordedAt' => self::toDateTime($row->recorded_at),
            ];
        }
    }

    public function countEventsInStream(string $executionId): int
    {
        $this->schema->ensure();

        return $this->connection->table($this->table)
            ->where('execution_id', $executionId)
            ->count();
    }

    private static function toDateTime(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw instanceof \DateTimeImmutable) {
            return $raw;
        }
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
    }
}
