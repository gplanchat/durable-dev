<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\MagentoBench\Fixture\GreetThenWait;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\DurableModule\Block\Adminhtml\ProcessDetail;
use Gplanchat\DurableModule\Block\Adminhtml\ProcessHistory;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunProjection;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\Escaper;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use PHPUnit\Framework\TestCase;

/**
 * #737: with `resource/durable` declared, the admin grid and the run page read the runs table and
 * the journal of the Magento-adapter backend.
 *
 * The grid goes through Magento's own UI component pipeline, as `mui/index/render` builds it: a
 * double of `Filter` once hid what the framework does to a filter value before the data provider
 * sees it (#815), and the page number it passes for the limit (#848).
 */
final class TheAdminReadsTheDatabaseCatalogueTest extends TestCase
{
    private static ?ObjectManagerInterface $objects = null;

    private MagentoWorkflowRunProjection $projection;

    protected function setUp(): void
    {
        $adapter = JournalHarness::adapter();
        $this->projection = new MagentoWorkflowRunProjection($adapter, new JournalSchema($adapter));
        if (null === self::$objects) {
            // One container for the whole class, put in the admin area once: the area is a state of it.
            self::$objects = BenchRuntime::objectManager();
            self::$objects->get(State::class)->setAreaCode(Area::AREA_ADMINHTML);
            self::$objects->configure(self::$objects->get(ConfigLoaderInterface::class)->load(Area::AREA_ADMINHTML));
        }
    }

    public function testTheGridListsTheRunsOfTheTableWithTheirStatusAndWhatTheyWaitOn(): void
    {
        $this->seed('order-1', 'App\\Order', wait: 'signal approve', picked: true);
        $this->seed('order-2', 'App\\Order', outcome: WorkflowRunStatus::Completed);

        $rows = $this->rowsByExecution($this->grid());

        self::assertSame(['order-1', 'order-2'], array_keys($rows));
        self::assertSame(['running', 'signal approve'], [$rows['order-1']['status'], $rows['order-1']['waiting_on']]);
        self::assertSame(['completed', '—'], [$rows['order-2']['status'], $rows['order-2']['waiting_on']]);
    }

    /** #818 reads picked_up_at: the grid must carry the fact, whatever it paints from it. */
    public function testTheGridExposesSinceWhenARunWaitsForAWorker(): void
    {
        $this->seed('order-1', 'App\\Order', picked: false);
        $this->seed('order-2', 'App\\Order', picked: true);

        $rows = $this->rowsByExecution($this->grid());

        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $rows['order-1']['waiting_for_worker_since']);
        self::assertSame('—', $rows['order-2']['waiting_for_worker_since']);
    }

    public function testTheNameFilterIsTheWholeNameAndTheStatusFilterTakesSeveralStates(): void
    {
        $this->seed('a-1', 'App\\Order');
        $this->seed('a-2', 'App\\Orders');
        $this->seed('b-1', 'App\\Order', outcome: WorkflowRunStatus::Failed);
        $this->seed('c-1', 'App\\Refund', outcome: WorkflowRunStatus::Completed);

        self::assertSame(['a-1', 'b-1'], $this->executions($this->grid(['workflow_name' => 'App\\Order'])));
        self::assertSame(['b-1', 'c-1'], $this->executions($this->grid(['status' => ['failed', 'completed']])));
        self::assertSame(['c-1'], $this->executions($this->grid(['status' => ['failed', 'completed'], 'workflow_name' => 'App\\Refund'])));
        self::assertSame(['a-1', 'a-2'], $this->executions($this->grid(['execution_id' => 'a-'])));
    }

    public function testThePagerPassesAPageNumberAndTheGridCountsTheWholeTable(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->seed(\sprintf('run-%02d', $i), 'App\\Order');
        }

        $second = $this->grid(page: 2, size: 20);

        self::assertSame(25, $second['totalRecords']);
        self::assertCount(5, $second['items']);
        self::assertCount(20, $this->grid(page: 1, size: 20)['items']);
    }

    public function testTheRunPageShowsTheJournalOfACompletedRun(): void
    {
        $runtime = BenchRuntime::factory()->create();
        $worker = $this->startWorker();

        try {
            $runtime->run(GreetThenWait::class, ['name' => 'Ada'], 'bench-greet');
        } finally {
            $this->stopWorker($worker);
        }

        $page = $this->runPage('bench-greet');

        self::assertStringContainsString('bench.greet-then-wait', $page);
        self::assertStringContainsString('Completed', $page);
        // The journal's own rows: the activity, then the one second timer the worker carried.
        self::assertStringContainsString('title="bench.greet"', $page);
        self::assertStringContainsString('durable-frieze-bar other', $page);
        self::assertStringNotContainsString('No execution named', $page);
    }

    public function testTheBannerNamesTheJournalDatabaseAndCountsItsRuns(): void
    {
        $this->seed('a-1', 'App\\Order');
        $this->seed('a-2', 'App\\Order', outcome: WorkflowRunStatus::Failed);

        $objects = self::$objects;
        self::assertNotNull($objects);
        $block = $objects->create(ProcessHistory::class, ['data' => ['template' => 'Gplanchat_DurableModule::process/notice.phtml']]);
        self::assertInstanceOf(ProcessHistory::class, $block);
        $block->setData('escaper', $objects->get(Escaper::class));
        $html = $block->toHtml();

        self::assertStringContainsString('Magento journal database answers', $html);
        self::assertStringNotContainsString('No Temporal DSN', $html);
        self::assertStringNotContainsString('worker has polled', $html);
        self::assertSame(['total' => 2, 'running' => 1, 'failed' => 1], array_intersect_key($block->getCounters(), ['total' => 0, 'running' => 0, 'failed' => 0]));
    }

    public function testTheRunPageSaysWhenNoRunHasThatId(): void
    {
        self::assertStringContainsString('No execution named', $this->runPage('nobody'));
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{totalRecords: int, items: list<array<string, mixed>>}
     */
    private function grid(array $filters = [], int $page = 1, int $size = 20): array
    {
        $objects = self::$objects;
        self::assertNotNull($objects);
        $objects->get(RequestInterface::class)->setParams([
            'namespace' => 'durable_process_listing',
            'filters' => $filters,
            'paging' => ['pageSize' => $size, 'current' => $page],
        ]);
        $listing = $objects->get(UiComponentFactory::class)->create('durable_process_listing');
        $this->prepare($listing);

        /** @var array{totalRecords: int, items: list<array<string, mixed>>} */
        return $listing->getContext()->getDataProvider()->getData();
    }

    private function prepare(UiComponentInterface $component): void
    {
        foreach ($component->getChildComponents() as $child) {
            $this->prepare($child);
        }
        $component->prepare();
    }

    /**
     * @param array{items: list<array<string, mixed>>} $grid
     *
     * @return array<string, array<string, mixed>>
     */
    private function rowsByExecution(array $grid): array
    {
        $rows = [];
        foreach ($grid['items'] as $row) {
            $rows[(string) $row['execution_id']] = $row;
        }
        ksort($rows);

        return $rows;
    }

    /**
     * @param array{items: list<array<string, mixed>>} $grid
     *
     * @return list<string>
     */
    private function executions(array $grid): array
    {
        return array_keys($this->rowsByExecution($grid));
    }

    private function seed(string $executionId, string $workflow, ?string $wait = null, bool $picked = false, ?WorkflowRunStatus $outcome = null): void
    {
        $id = ExecutionId::fromString($executionId);
        $this->projection->recordStart($id, $workflow);
        if ($picked) {
            $this->projection->recordPickup($id);
        }
        if (null !== $wait) {
            $this->projection->recordWait($id, $wait);
        }
        if (null !== $outcome) {
            $this->projection->recordOutcome($id, $outcome);
        }
    }

    private function runPage(string $runId): string
    {
        $objects = self::$objects;
        self::assertNotNull($objects);
        $objects->get(RequestInterface::class)->setParams(['run_id' => $runId]);
        $block = $objects->create(ProcessDetail::class, ['data' => ['template' => 'Gplanchat_DurableModule::process/detail.phtml']]);
        self::assertInstanceOf(ProcessDetail::class, $block);
        $block->setData('escaper', $objects->get(Escaper::class));

        return $block->toHtml();
    }

    /** @return array{resource, string} */
    private function startWorker(): array
    {
        $dir = sys_get_temp_dir() . '/durable-drain-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $worker = proc_open([\PHP_BINARY, __DIR__ . '/drain.php', $dir], [1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/stderr', 'w']], $pipes);
        self::assertIsResource($worker);

        return [$worker, $dir];
    }

    /** @param array{resource, string} $worker */
    private function stopWorker(array $worker): void
    {
        [$process, $dir] = $worker;
        touch($dir . '/stop');
        proc_close($process);
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);
    }
}
