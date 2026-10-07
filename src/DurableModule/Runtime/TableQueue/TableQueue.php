<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\TableQueue;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The `durable_queue` table (#746) on the journal's connection, without Magento's MessageQueue
 * (DUR046) and without Messenger. At-least-once: a take leases the row instead of deleting it, so a
 * worker killed mid-message leaves it to come back when the lease ends. The attempt claim and the
 * journal's guards make the second copy harmless.
 *
 * Every time comes from the database (`NOW(3)`), never from a worker's clock. MySQL 8.0 and MariaDB
 * 10.6 or later, for `SKIP LOCKED`.
 *
 * ponytail: millisecond precision (the columns are `DATETIME(3)`, #958), bodies are strings the caller serializes.
 */
final class TableQueue
{
    private const TABLE = 'durable_queue';
    private const LEASE_CONFIG_PATH = 'durable/queue/lease_seconds';
    private const CLAIM_TTL_CONFIG_PATH = 'durable/queue/attempt_claim_ttl_seconds';
    private const DEFAULT_LEASE_SECONDS = 600;
    private const DEFAULT_CLAIM_TTL_SECONDS = 300;

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

    /** The lease and the claim TTL from `app/etc/env.php`: `durable/queue/lease_seconds` (600) and `durable/queue/attempt_claim_ttl_seconds` (300, the DBAL claim's default). */
    public static function configured(AdapterInterface $connection, DeploymentConfig $config): self
    {
        [$lease, $claim] = self::configuredDurations($config);

        return new self($connection, $lease, $claim);
    }

    /** @return array{int, int} lease, claim TTL */
    public static function configuredDurations(DeploymentConfig $config): array
    {
        return [
            (int) $config->get(self::LEASE_CONFIG_PATH, self::DEFAULT_LEASE_SECONDS),
            (int) $config->get(self::CLAIM_TTL_CONFIG_PATH, self::DEFAULT_CLAIM_TTL_SECONDS),
        ];
    }

    public function enqueue(string $queue, string $body, float $delaySeconds = 0.0): void
    {
        $this->connection->query(
            'INSERT INTO ' . self::TABLE . ' (queue_name, body, available_at) VALUES (?, ?, NOW(3) + INTERVAL ? MICROSECOND)',
            [$queue, $body, max(0, (int) round($delaySeconds * 1_000_000.0))],
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
                'SELECT id, body FROM ' . self::TABLE . ' WHERE queue_name = ? AND available_at <= NOW(3) AND (leased_until IS NULL OR leased_until < NOW(3)) ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED',
                [$queue],
            );
            if (!\is_array($row)) {
                $this->connection->commit();

                return null;
            }
            $this->connection->query('UPDATE ' . self::TABLE . ' SET leased_until = NOW(3) + INTERVAL ? SECOND WHERE id = ?', [$this->leaseSeconds, $row['id']]);
            $token = (string) $this->connection->fetchOne('SELECT leased_until FROM ' . self::TABLE . ' WHERE id = ?', [$row['id']]);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return new QueuedMessage((int) $row['id'], (string) $row['body'], $token);
    }

    /**
     * Deletes the message if this delivery still owns it. A copy redelivered after the lease ran out
     * has a later `leased_until`: the first worker's late ack deletes nothing and answers false.
     */
    public function ack(QueuedMessage $message): bool
    {
        return 1 === $this->connection->query('DELETE FROM ' . self::TABLE . ' WHERE id = ? AND leased_until = ?', [$message->id, $message->leaseToken])->rowCount();
    }
}
