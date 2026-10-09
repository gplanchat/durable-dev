<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Since #867 the panel draws the shared run timeline, not the per-event store segments. The
 * profile no longer carries them, and the event count and truncation flag the panel shows still
 * come from the journal (#876).
 */
final class TheProfilerKeepsNoStoreTimelineTest extends TestCase
{
    public function testTheProfileCarriesNoStoreTimeline(): void
    {
        $collector = $this->collect();

        self::assertSame(['process'], array_keys($collector->getTimeFrame()));
        foreach ($collector->getExecutionsDetail() as $detail) {
            self::assertArrayNotHasKey('storeTimeline', $detail);
        }
    }

    public function testTheEventCountAndTruncationFlagComeFromTheJournal(): void
    {
        $details = [];
        foreach ($this->collect()->getExecutionsDetail() as $detail) {
            $details[$detail['executionId']] = [$detail['storeEventCount'], $detail['storeTruncated']];
        }

        self::assertSame(['exec-long' => [501, true], 'exec-short' => [3, false]], $details);
    }

    private function collect(): DurableDataCollector
    {
        $store = new InMemoryEventStore();
        foreach (['exec-short' => 3, 'exec-long' => 501] as $id => $events) {
            for ($i = 0; $i < $events; ++$i) {
                $store->append(new ActivityScheduled(ExecutionId::fromString($id), 'act-' . $i, 'charge', []));
            }
        }

        $collector = new DurableDataCollector(new DurableExecutionTrace(), new InMemoryWorkflowMetadataStore(), $store);
        $collector->collect(new Request(['durable_execution' => 'exec-short,exec-long']), new Response());

        return $collector;
    }
}
