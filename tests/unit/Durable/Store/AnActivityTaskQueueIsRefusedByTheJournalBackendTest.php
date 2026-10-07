<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\Transport\NoopActivityTransport;
use PHPUnit\Framework\TestCase;

/**
 * Only Temporal routes an activity by task queue (#977). A journal backend (InMemory, DBAL,
 * Illuminate) reads the name nowhere, so it refuses it instead of running the activity elsewhere
 * than the application asked.
 */
final class AnActivityTaskQueueIsRefusedByTheJournalBackendTest extends TestCase
{
    public function testANamedQueueIsRefusedNamingTheOptionAndTheBackend(): void
    {
        $this->expectException(UnsupportedByBackendException::class);
        $this->expectExceptionMessage('The activity task queue "payments", set on ActivityOptions::$taskQueue, #[Activities(taskQueue:)] or activityStub()');
        $this->expectExceptionMessage('InMemory, DBAL, Illuminate and Magento Database');

        $this->buffer()->scheduleActivity('a-1', 'charge', [], new ActivityOptions(taskQueue: TaskQueue::named('payments')));
    }

    public function testNoQueueIsAccepted(): void
    {
        $this->buffer()->scheduleActivity('a-1', 'charge', [], new ActivityOptions());
        $this->buffer()->scheduleActivity('a-2', 'charge', [], null);
        $this->addToAssertionCount(1);
    }

    private function buffer(): EventStoreCommandBuffer
    {
        return new EventStoreCommandBuffer(new InMemoryEventStore(), new NoopActivityTransport(), ExecutionId::fromString('exec-1'));
    }
}
