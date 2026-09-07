<?php

declare(strict_types=1);

namespace Gplanchat\Bridge\Dbal\Store;

use Doctrine\DBAL\Connection;
use Gplanchat\Bridge\Dbal\Schema\DurableSchema;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\EventStoreInterface;

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
final class DbalEventStore implements EventStoreInterface
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

        $this->connection->insert($this->table, [
            'execution_id' => $record['execution_id'],
            'event_type' => $record['event_type'],
            'payload' => json_encode($record['payload'], \JSON_THROW_ON_ERROR),
            'recorded_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ], [
            'recorded_at' => 'datetime_immutable',
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

        $rows = $this->connection->executeQuery(
            \sprintf('SELECT event_type, payload, recorded_at FROM %s WHERE execution_id = ? ORDER BY id ASC', $this->table),
            [$executionId],
        );

        foreach ($rows->iterateAssociative() as $row) {
            yield [
                'event' => EventDataMapper::toDomainEvent([
                    'execution_id' => $executionId,
                    'event_type' => $row['event_type'],
                    'payload' => $row['payload'],
                ]),
                'recordedAt' => self::toDateTime($row['recorded_at']),
            ];
        }
    }

    public function countEventsInStream(string $executionId): int
    {
        $this->schema->ensure();

        return (int) $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM %s WHERE execution_id = ?', $this->table),
            [$executionId],
        );
    }

    /**
     * Platforms return `recorded_at` as a string (SQLite, MySQL) or an object (PostgreSQL, driver-dependent).
     */
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
