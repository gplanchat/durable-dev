<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Runtime\ResumeLock;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * `GET_LOCK` on the journal's connection. The server releases the lock when that connection ends,
 * so a killed worker frees it as soon as the server sees the socket close.
 *
 * Magento's adapter reconnects silently after error 2006 or 2013 (`Mysql::performQuery()`), and
 * the new session holds nothing. `holds()` compares `IS_USED_LOCK()` with `CONNECTION_ID()` for
 * that reason; `tryAcquire()` trusts nothing it remembers.
 */
final class GetLockResumeLock implements ResumeLock
{
    /** @var array<string, true> */
    private array $held = [];

    public function __construct(private readonly AdapterInterface $connection) {}

    public function tryAcquire(string $key): bool
    {
        if ($this->holds($key)) {
            return false;
        }
        unset($this->held[$key]);

        if ('1' !== (string) $this->connection->fetchOne('SELECT GET_LOCK(?, 0)', [self::name($key)])) {
            return false;
        }
        $this->held[$key] = true;

        return true;
    }

    public function holds(string $key): bool
    {
        return isset($this->held[$key])
            && '1' === (string) $this->connection->fetchOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID()', [self::name($key)]);
    }

    public function release(string $key): void
    {
        if (isset($this->held[$key])) {
            $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [self::name($key)]);
            unset($this->held[$key]);
        }
    }

    /** A lock name is limited to 64 characters: 15 of prefix and 40 of hash. */
    private static function name(string $key): string
    {
        return 'durable_resume_' . sha1($key);
    }
}
