<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\NoLocalJournalEventStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DUR051: where there is no local journal, the store says so. It used to answer an empty history,
 * which is what a run that has not started looks like: a workflow given it would run its
 * activities again instead of failing.
 */
final class NoLocalJournalEventStoreTest extends TestCase
{
    /**
     * @return iterable<string, array{\Closure(NoLocalJournalEventStore): mixed, string}>
     */
    public static function everyCall(): iterable
    {
        yield 'append' => [static fn(NoLocalJournalEventStore $store) => $store->append(new ExecutionStarted(ExecutionId::fromString('exec-1'), [])), 'append'];
        yield 'readStream' => [static fn(NoLocalJournalEventStore $store) => iterator_to_array($store->readStream(ExecutionId::fromString('exec-1'))), 'readStream'];
        yield 'readStreamWithRecordedAt' => [static fn(NoLocalJournalEventStore $store) => iterator_to_array($store->readStreamWithRecordedAt(ExecutionId::fromString('exec-1'))), 'readStreamWithRecordedAt'];
        yield 'countEventsInStream' => [static fn(NoLocalJournalEventStore $store) => $store->countEventsInStream(ExecutionId::fromString('exec-1')), 'countEventsInStream'];
    }

    /**
     * @param \Closure(NoLocalJournalEventStore): mixed $call
     */
    #[DataProvider('everyCall')]
    public function testEveryCallIsRefused(\Closure $call, string $method): void
    {
        $this->expectException(UnsupportedByBackendException::class);
        $this->expectExceptionMessage($method . '()');

        $call(new NoLocalJournalEventStore('Temporal'));
    }
}
