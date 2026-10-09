<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Store;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\StoredTimestamp;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The event journal on the journal's own Magento adapter (DUR056), the counterpart of
 * `DbalEventStore`: same rows, same mapper, same insertion order.
 *
 * An append is one transaction, and it refuses to begin inside another one: MySQL has no nested
 * transactions, and an inner `rollBack()` makes the outer `commit()` throw (spike #709). It never
 * creates a table: a missing one is named (`durable:setup` creates them, #746).
 *
 * ponytail: no pass fence; #749 adds it. The store stays generic over event types, it only
 * persists what {@see EventDataMapper} maps.
 */
final class MagentoEventStore implements EventStoreInterface
{
    private const TABLE = 'durable_events';

    private readonly JournalSchema $schema;

    public function __construct(private readonly AdapterInterface $connection)
    {
        $this->schema = new JournalSchema($connection);
    }

    public function append(Event $event): void
    {
        $this->schema->assertInstalled();
        if (0 !== $this->connection->getTransactionLevel()) {
            throw new \RuntimeException('The journal refuses to append inside an open transaction: MySQL has no nested transactions. Append from a unit of work that opened none on the journal\'s connection.');
        }

        $record = EventDataMapper::fromDomainEvent($event);

        $this->connection->beginTransaction();

        try {
            $this->connection->insert(self::TABLE, [
                'execution_id' => $record['execution_id'],
                'event_type' => $record['event_type'],
                'payload' => json_encode($record['payload'], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                'recorded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
            ]);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    public function readStream(ExecutionId $executionId): iterable
    {
        foreach ($this->readStreamWithRecordedAt($executionId) as $entry) {
            yield $entry['event'];
        }
    }

    public function readStreamWithRecordedAt(ExecutionId $executionId): iterable
    {
        $this->schema->assertInstalled();

        $rows = $this->connection->query(
            'SELECT event_type, payload, recorded_at FROM ' . self::TABLE . ' WHERE execution_id = ? ORDER BY id ASC',
            [$executionId->toString()],
        );

        while (false !== ($row = $rows->fetch(\PDO::FETCH_ASSOC))) {
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
        $this->schema->assertInstalled();

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE execution_id = ?',
            [$executionId->toString()],
        );
    }
}
