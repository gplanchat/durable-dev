<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Tests\Unit\Store;

use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Tests\Support\DurableTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 */
#[CoversClass(InMemoryEventStore::class)]
final class InMemoryEventStoreTest extends DurableTestCase
{
    #[Test]
    public function appendAndReadStream(): void
    {
        $this->stack();
        $store = $this->eventStore();
        $executionId = $this->executionId();

        $store->append(new ExecutionStarted($executionId));
        $store->append(new ActivityScheduled($executionId, 'act-1', 'testActivity', []));
        $store->append(new ActivityCompleted($executionId, 'act-1', 'result'));

        $events = iterator_to_array($store->readStream($executionId));

        self::assertCount(3, $events);
        self::assertInstanceOf(ExecutionStarted::class, $events[0]);
        self::assertInstanceOf(ActivityScheduled::class, $events[1]);
        self::assertInstanceOf(ActivityCompleted::class, $events[2]);
    }

    #[Test]
    public function isolationBetweenExecutions(): void
    {
        $this->stack();
        $store = $this->eventStore();

        $store->append(new ExecutionStarted('exec-1'));
        $store->append(new ExecutionStarted('exec-2'));

        $events1 = iterator_to_array($store->readStream('exec-1'));
        $events2 = iterator_to_array($store->readStream('exec-2'));

        self::assertCount(1, $events1);
        self::assertCount(1, $events2);
        self::assertSame('exec-1', $events1[0]->executionId());
        self::assertSame('exec-2', $events2[0]->executionId());
    }
}
