<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Store;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use Gplanchat\Durable\Store\PassFence;
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
 * It fences passes (DUR053): `claimPass()` bumps the execution's epoch on the heads row, and
 * `appendFenced()` reads it under a shared lock and writes only while it is the fence's own. The
 * store stays generic over event types, it only persists what {@see EventDataMapper} maps.
 */
final class MagentoEventStore implements FencedEventStoreInterface
{
    private const TABLE = 'durable_events';
    private const HEADS = 'durable_execution_heads';

    private readonly JournalSchema $schema;

    public function __construct(private readonly AdapterInterface $connection)
    {
        $this->schema = new JournalSchema($connection);
    }

    public function append(Event $event): void
    {
        $this->inTransaction(fn() => $this->insertEvent($event));
    }

    public function claimPass(ExecutionId $executionId): PassFence
    {
        $id = $executionId->toString();

        // The upsert locks the heads row until the claim commits: a fenced append waits for it (DUR053).
        $epoch = $this->inTransaction(function () use ($id): int {
            $this->connection->query(
                'INSERT INTO ' . self::HEADS . ' (execution_id, epoch) VALUES (?, 1) ON DUPLICATE KEY UPDATE epoch = epoch + 1',
                [$id],
            );

            return (int) $this->connection->fetchOne('SELECT epoch FROM ' . self::HEADS . ' WHERE execution_id = ?', [$id]);
        });

        return new PassFence($id, $epoch);
    }

    public function appendFenced(Event $event, PassFence $fence): void
    {
        if (!$fence->fences()) {
            $this->append($event);

            return;
        }

        $this->inTransaction(function () use ($event, $fence): void {
            // The shared lock makes a claim's update wait for this append, and this read wait for a claim.
            $epoch = (int) $this->connection->fetchOne(
                'SELECT epoch FROM ' . self::HEADS . ' WHERE execution_id = ? LOCK IN SHARE MODE',
                [$fence->executionId],
            );
            if ($epoch !== $fence->epoch) {
                throw SupersededPassException::for($fence);
            }
            $this->insertEvent($event);
        });
    }

    /**
     * One unit of work is one transaction on the journal's adapter, never nested: MySQL has no
     * nested transactions, and an inner `rollBack()` makes the outer `commit()` throw (spike #709).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function inTransaction(callable $work): mixed
    {
        $this->schema->assertInstalled();
        if (0 !== $this->connection->getTransactionLevel()) {
            throw new \RuntimeException('The journal refuses to write inside an open transaction: MySQL has no nested transactions. Write from a unit of work that opened none on the journal\'s connection.');
        }

        $this->connection->beginTransaction();

        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    private function insertEvent(Event $event): void
    {
        $record = EventDataMapper::fromDomainEvent($event);

        $this->connection->insert(self::TABLE, [
            'execution_id' => $record['execution_id'],
            'event_type' => $record['event_type'],
            'payload' => json_encode($record['payload'], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
            'recorded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
        ]);
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
