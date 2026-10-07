<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * #851: the metadata of a run exists from its dispatch, so a run dispatched on the request and not
 * executed yet has metadata and an empty journal. The panel reads it as queued, not running.
 */
final class TheProfilerSaysADispatchedRunIsQueuedTest extends TestCase
{
    public function testARunDispatchedButNotExecutedIsQueued(): void
    {
        $executionId = ExecutionId::fromString('exec-1');
        $metadata = new InMemoryWorkflowMetadataStore();
        $metadata->save($executionId, 'App\\OrderWorkflow', ['orderId' => 42]);
        $trace = new DurableExecutionTrace();
        $trace->onWorkflowDispatchRequested($executionId, 'App\\OrderWorkflow', ['orderId' => 42], false, 'async');

        $collector = new DurableDataCollector($trace, $metadata, new InMemoryEventStore());
        $collector->collect(new Request(), new Response());

        self::assertSame('Queued (no journal yet)', $collector->getExecutionsDetail()[0]['executionStatusLabel']);
    }
}
