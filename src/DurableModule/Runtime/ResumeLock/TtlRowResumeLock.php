<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\ResumeLock;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\DB\Ddl\Table;

/**
 * A row per held lock, with an owner token and an expiry. A killed holder keeps its row until the
 * expiry passes: that is this lock's bound, where `GetLockResumeLock` is bound by the server
 * noticing a closed connection.
 *
 * Every time comes from the database clock (`NOW(3)`), so the workers' clocks do not matter. A
 * holder that outlives the TTL loses the lock to another worker without being told; `holds()`
 * is how it finds out. There is no renewal: a resume that can outlive the TTL needs one.
 */
final class TtlRowResumeLock implements ResumeLock
{
    private const NOW_MS = 'CAST(UNIX_TIMESTAMP(NOW(3)) * 1000 AS UNSIGNED)';

    /** @var array<string, string> key => owner token */
    private array $tokens = [];

    public function __construct(
        private readonly AdapterInterface $connection,
        private readonly int $ttlSeconds = 30,
        private readonly string $table = 'durable_resume_lock',
    ) {}

    /**
     * The table, declared and not created: `bin/magento durable:setup` creates it (#746), a
     * store never issues DDL.
     */
    public static function table(AdapterInterface $connection, string $name = 'durable_resume_lock'): Table
    {
        return $connection->newTable($name)
            ->addColumn('lock_key', Table::TYPE_TEXT, 40, ['nullable' => false, 'primary' => true])
            ->addColumn('token', Table::TYPE_TEXT, 32, ['nullable' => false])
            ->addColumn('expires_at_ms', Table::TYPE_BIGINT, null, ['nullable' => false, 'unsigned' => true]);
    }

    public function tryAcquire(string $key): bool
    {
        $token = bin2hex(random_bytes(16));
        $expiry = new \Zend_Db_Expr(self::NOW_MS . ' + ' . $this->ttlSeconds * 1000);

        try {
            $this->connection->insert($this->table, ['lock_key' => sha1($key), 'token' => $token, 'expires_at_ms' => $expiry]);
        } catch (DuplicateException) {
            // The row exists: it is ours to take only if it has expired.
            $taken = $this->connection->update(
                $this->table,
                ['token' => $token, 'expires_at_ms' => $expiry],
                ['lock_key = ?' => sha1($key), 'expires_at_ms <= ' . self::NOW_MS],
            );
            if (1 !== $taken) {
                return false;
            }
        }
        $this->tokens[$key] = $token;

        return true;
    }

    public function holds(string $key): bool
    {
        return isset($this->tokens[$key]) && '1' === (string) $this->connection->fetchOne(
            'SELECT 1 FROM ' . $this->table . ' WHERE lock_key = ? AND token = ? AND expires_at_ms > ' . self::NOW_MS,
            [sha1($key), $this->tokens[$key]],
        );
    }

    public function release(string $key): void
    {
        if (isset($this->tokens[$key])) {
            $this->connection->delete($this->table, ['lock_key = ?' => sha1($key), 'token = ?' => $this->tokens[$key]]);
            unset($this->tokens[$key]);
        }
    }
}
