<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\TableQueue;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The `durable_queue` table (#746) on the journal's connection, without Magento's MessageQueue
 * (DUR046) and without Messenger. At-least-once: a take leases the row instead of deleting it, so a
 * worker killed mid-message leaves it to come back when the lease ends. The attempt claim and the
 * journal's guards make the second copy harmless.
 *
 * Every time comes from the database (`NOW()`), never from a worker's clock. MySQL 8.0 and MariaDB
 * 10.6 or later, for `SKIP LOCKED`.
 *
 * ponytail: second precision (the column is a DATETIME), bodies are strings the caller serializes,
 * and an `ack` after the lease ran out deletes the redelivered copy too. Fractional seconds and a
 * lease token if a delay below one second or a slow handler matters.
 */
final class TableQueue
{
    private const TABLE = 'durable_queue';

    /**
     * @param int $leaseSeconds           how long a taken message is invisible to the other workers
     * @param int $attemptClaimTtlSeconds the TTL of the activity attempt claim (#753): the lease must outlast it
     */
    public function __construct(
        private readonly AdapterInterface $connection,
        private readonly int $leaseSeconds,
        int $attemptClaimTtlSeconds,
    ) {
        if ($leaseSeconds <= $attemptClaimTtlSeconds) {
            throw new \InvalidArgumentException(\sprintf('The queue lease (%d s) must be longer than the activity attempt claim TTL (%d s): a copy delivered at the end of the lease would run while the first still holds the claim.', $leaseSeconds, $attemptClaimTtlSeconds));
        }
    }

    public function enqueue(string $queue, string $body, int $delaySeconds = 0): void
    {
        $this->connection->query(
            'INSERT INTO ' . self::TABLE . ' (queue_name, body, available_at) VALUES (?, ?, NOW() + INTERVAL ? SECOND)',
            [$queue, $body, max(0, $delaySeconds)],
        );
    }

    /**
     * Leases the oldest due message, or returns null. One short transaction, opened here: the
     * adapter does not nest them, an inner `rollBack()` makes the outer `commit()` throw.
     */
    public function take(string $queue): ?QueuedMessage
    {
        if (0 !== $this->connection->getTransactionLevel()) {
            throw new \LogicException('TableQueue::take() opens its own transaction: call it outside any other.');
        }

        $this->connection->beginTransaction();

        try {
            $row = $this->connection->fetchRow(
                'SELECT id, body FROM ' . self::TABLE . ' WHERE queue_name = ? AND available_at <= NOW() AND (leased_until IS NULL OR leased_until < NOW()) ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED',
                [$queue],
            );
            if (!\is_array($row)) {
                $this->connection->commit();

                return null;
            }
            $this->connection->query('UPDATE ' . self::TABLE . ' SET leased_until = NOW() + INTERVAL ? SECOND WHERE id = ?', [$this->leaseSeconds, $row['id']]);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return new QueuedMessage((int) $row['id'], (string) $row['body']);
    }

    public function ack(int $id): void
    {
        $this->connection->query('DELETE FROM ' . self::TABLE . ' WHERE id = ?', [$id]);
    }
}
