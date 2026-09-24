<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableBundle;

use Gplanchat\Durable\Bundle\DataCollector\DurableDataCollector;
use Gplanchat\Durable\Bundle\Profiler\DurableExecutionTrace;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\Durable\Store\InMemoryWorkflowMetadataStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `?durable_execution=` names journals to read on this request. Each one costs a journal read, so
 * the list is bounded and an id that no store would have issued is dropped (#335).
 */
final class ProfilerExecutionQueryIsBoundedTest extends TestCase
{
    public function testAnIdThatNoStoreIssuesIsDropped(): void
    {
        $ids = $this->idsCollectedFor('0199a3c4-7e1f-7b2a-9c3d-4e5f6a7b8c9d, order:42/child-1,<script>,a b,' . str_repeat('x', 256));

        self::assertSame(['0199a3c4-7e1f-7b2a-9c3d-4e5f6a7b8c9d', 'order:42/child-1'], $ids);
    }

    public function testTheListIsCapped(): void
    {
        $ids = $this->idsCollectedFor(implode(',', array_map(static fn(int $i): string => "exec-{$i}", range(1, 50))));

        self::assertCount(DurableDataCollector::MAX_QUERIED_EXECUTIONS, $ids);
    }

    public function testARepeatedIdCountsOnce(): void
    {
        $ids = $this->idsCollectedFor(str_repeat('exec-1,', 30) . 'exec-2');

        self::assertSame(['exec-1', 'exec-2'], $ids);
    }

    /**
     * @return list<string>
     */
    private function idsCollectedFor(string $query): array
    {
        $collector = new DurableDataCollector(new DurableExecutionTrace(), new InMemoryWorkflowMetadataStore(), new InMemoryEventStore());
        $collector->collect(new Request(['durable_execution' => $query]), new Response());

        return $collector->getExecutionIds();
    }
}
