<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Observation\KeyPatternPayloadRedactor;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use Gplanchat\Durable\Store\InMemoryWorkflowRunCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel says what a suspended run waits on (#324, carried to #264), read by the run's id from
 * the catalog: a `listRuns()` window would silently miss any run older than the window.
 */
final class TheProfilerSaysWhatARunWaitsOnTest extends TestCase
{
    public function testASuspendedRunSaysWhatItWaitsOn(): void
    {
        $events = new InMemoryEventStore();
        $catalog = new InMemoryWorkflowRunCatalog($events);
        $catalog->recordStart('exec-1', 'App\\OrderWorkflow');
        $catalog->recordWait('exec-1', 'timer due at 2026-09-24T10:00:00+00:00');

        $detail = $this->collect($catalog, $events)->getExecutionsDetail()[0];

        self::assertSame('timer due at 2026-09-24T10:00:00+00:00', $detail['waitingOn'] ?? null);
    }

    public function testWithoutACatalogThePanelSaysNothingOfTheKind(): void
    {
        self::assertNull($this->collect(null, new InMemoryEventStore())->getExecutionsDetail()[0]['waitingOn'] ?? null);
    }

    private function collect(?InMemoryWorkflowRunCatalog $catalog, InMemoryEventStore $events): DurableDataCollector
    {
        $trace = new DurableExecutionTrace();
        $trace->onWorkflowDispatchRequested('exec-1', 'App\\OrderWorkflow', [], false, 'async');

        $collector = new DurableDataCollector($trace, new InMemoryWorkflowMetadataStore(), $events, new KeyPatternPayloadRedactor(), $catalog);
        $collector->collect(new Request(), new Response());

        return $collector;
    }
}
