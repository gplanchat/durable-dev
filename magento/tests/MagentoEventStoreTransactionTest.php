<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\DurableModule\Store\MagentoEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A unit of work is one transaction on the journal's adapter, never nested: MySQL has no nested
 * transactions, and the spike measured an inner rollBack() making the outer commit() throw.
 */
final class MagentoEventStoreTransactionTest extends TestCase
{
    public function testAnAppendInsideAnOpenTransactionThrowsAndWritesNothing(): void
    {
        $adapter = JournalHarness::adapter();
        $store = new MagentoEventStore($adapter);
        $id = ExecutionId::fromString('exec-nested');

        $adapter->beginTransaction();

        try {
            $store->append(new ExecutionStarted($id));
            self::fail('the append must refuse to run inside an open transaction');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('transaction', $e->getMessage());
        } finally {
            self::assertSame(1, $adapter->getTransactionLevel(), 'the caller\'s transaction is left as it was');
            $adapter->rollBack();
        }

        self::assertSame(0, $store->countEventsInStream($id));
        self::assertSame(0, $adapter->getTransactionLevel());
    }

    public function testAnAppendLeavesNoTransactionOpen(): void
    {
        $adapter = JournalHarness::adapter();
        (new MagentoEventStore($adapter))->append(new ExecutionStarted(ExecutionId::fromString('exec-flat')));

        self::assertSame(0, $adapter->getTransactionLevel());
    }
}
