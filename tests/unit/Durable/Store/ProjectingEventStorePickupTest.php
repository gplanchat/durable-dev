<?php

declare(strict_types=1);

namespace unit\Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use Gplanchat\Durable\Store\ProjectingEventStore;
use PHPUnit\Framework\TestCase;

/**
 * A run is picked up when a worker appends its first event, `ExecutionStarted` (#447). Until then
 * the run list can say it waits for a worker, and since when.
 */
final class ProjectingEventStorePickupTest extends TestCase
{
    public function testTheFirstExecutionStartedRecordsThePickup(): void
    {
        $events = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($events);
        $catalog->recordStart('exec-1', 'App\\OrderWorkflow');

        self::assertNotNull($catalog->listRuns()->runs[0]->waitingForWorkerSince, 'dispatched, nobody consumed it');

        (new ProjectingEventStore($events, $catalog))->append(new ExecutionStarted('exec-1', []));

        self::assertNull($catalog->listRuns()->runs[0]->waitingForWorkerSince);
    }
}
