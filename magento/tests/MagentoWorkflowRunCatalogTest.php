<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\RunPageCursor;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Store\InMemoryEventStore;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunCatalog;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

/**
 * What the shared cases cannot say about the Magento adapter (#752): dates come back from
 * DATETIME(3) as strings with milliseconds, the cursor carries them, and MySQL's collation must not
 * decide what a filter matches. Rows are inserted directly so that the dates are the test's.
 */
final class MagentoWorkflowRunCatalogTest extends TestCase
{
    private AdapterInterface $adapter;
    private MagentoWorkflowRunCatalog $catalog;

    protected function setUp(): void
    {
        $this->adapter = JournalHarness::adapter();
        $this->catalog = new MagentoWorkflowRunCatalog($this->adapter, new JournalSchema($this->adapter), new JournalRunHistoryReader(new InMemoryEventStore()));
    }

    public function testRunsOfTheSameSecondPageByMillisecondWithoutLossOrRepeat(): void
    {
        $this->insert('a', 'App\\W', 'running', '2026-10-05 10:00:00.100');
        $this->insert('b', 'App\\W', 'running', '2026-10-05 10:00:00.300');
        $this->insert('c', 'App\\W', 'running', '2026-10-05 10:00:00.200');

        $seen = [];
        $cursor = null;
        do {
            $page = $this->catalog->listRuns(cursor: $cursor, limit: 1);
            $seen = [...$seen, ...$this->idsOf($page->runs)];
            $cursor = $page->nextCursor;
        } while (null !== $cursor && \count($seen) < 10);

        self::assertSame(['b', 'c', 'a'], $seen, 'the most recent first, the millisecond breaks the tie');
    }

    public function testTheCursorCarriesTheStoredMillisecondDate(): void
    {
        $this->insert('a', 'App\\W', 'running', '2026-10-05 10:00:00.250');
        $this->insert('b', 'App\\W', 'running', '2026-10-05 10:00:00.125');

        $page = $this->catalog->listRuns(limit: 1);

        self::assertSame('2026-10-05 10:00:00.250', RunPageCursor::decode($page->nextCursor)?->startedAt);
        self::assertSame('250', $page->runs[0]->startedAt->format('v'));
        self::assertSame('UTC', $page->runs[0]->startedAt->getTimezone()->getName());
    }

    public function testAPagePastTheLastRunIsEmptyAndDoesNotFail(): void
    {
        $this->insert('a', 'App\\W', 'running', '2026-10-05 10:00:00.100');

        $page = $this->catalog->listRuns(cursor: (new RunPageCursor('2026-10-05 10:00:00.100', 'a'))->encode());

        self::assertSame([], $page->runs);
        self::assertNull($page->nextCursor);
    }

    public function testTheOutcomeCountersAddUpToWhatThePageShows(): void
    {
        foreach (['running', 'running', 'completed', 'failed', 'cancelled', 'continued_as_new'] as $i => $status) {
            $this->insert('exec-' . $i, 'App\\W', $status, \sprintf('2026-10-05 10:00:0%d.000', $i));
        }

        $counters = RunDashboard::outcomeCounters($this->catalog->listRuns()->runs);

        self::assertSame(6, $counters['total']);
        self::assertSame(2, $counters['running']);
        self::assertSame(1, $counters['continued_as_new']);
        self::assertSame($counters['total'], array_sum(array_diff_key($counters, ['total' => 0])), 'every run is in a bucket');
        self::assertSame(2, RunDashboard::outcomeCounters($this->catalog->listRuns(WorkflowRunStatus::Running)->runs)['total']);
    }

    public function testTheCollationDoesNotWidenAFilter(): void
    {
        $this->insert('Élan-1', 'App\\Ordér', 'running', '2026-10-05 10:00:00.000');
        $this->insert('elan-2', 'App\\Order', 'running', '2026-10-05 10:00:01.000');

        self::assertSame(['elan-2'], $this->idsOf($this->catalog->listRuns(filter: new WorkflowRunFilter(workflowName: 'App\\Order'))->runs));
        self::assertSame(['Élan-1'], $this->idsOf($this->catalog->listRuns(filter: new WorkflowRunFilter(executionIdPrefix: 'Él'))->runs));
        self::assertSame(['elan-2'], $this->idsOf($this->catalog->listRuns(filter: new WorkflowRunFilter(executionIdPrefix: 'el'))->runs));
    }

    /**
     * @param list<WorkflowRunDescription> $runs
     *
     * @return list<string>
     */
    private function idsOf(array $runs): array
    {
        return array_map(static fn(WorkflowRunDescription $run): string => $run->executionId, $runs);
    }

    private function insert(string $executionId, string $type, string $status, string $startedAt): void
    {
        $this->adapter->insert('durable_workflow_runs', [
            'execution_id' => $executionId,
            'workflow_type' => $type,
            'status' => $status,
            'started_at' => $startedAt,
        ]);
    }
}
