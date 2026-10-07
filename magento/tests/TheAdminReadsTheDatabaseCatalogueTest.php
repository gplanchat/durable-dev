<?php

declare(strict_types=1);

namespace Gplanchat\Durable\MagentoBench;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\DurableModule\Schema\JournalSchema;
use Gplanchat\DurableModule\Store\MagentoWorkflowRunProjection;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponentInterface;
use PHPUnit\Framework\TestCase;

/**
 * #737: with `resource/durable` declared, the admin grid reads the runs table of the Magento-adapter backend.
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
}
