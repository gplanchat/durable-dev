<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Ui\DataProvider\ProcessListing;
use Magento\Framework\Api\Filter;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture/magento-data-provider.php';

/**
 * #514: the grid's run column, its link to the run page and its filter carry the id the
 * application started the run with. On Temporal the run id is the server's own.
 */
final class TheGridNamesARunByItsExecutionIdTest extends TestCase
{
    public function testTheRunColumnIsTheExecutionId(): void
    {
        self::assertSame(['order/42'], array_column($this->items(), 'run_id'));
    }

    public function testTheRunFilterMatchesTheExecutionId(): void
    {
        self::assertSame(['order/42'], array_column($this->items(self::runFilter('order/')), 'run_id'));
        self::assertSame([], $this->items(self::runFilter('server-run')));
    }

    private static function runFilter(string $value): Filter
    {
        return new class ($value) extends Filter {
            public function __construct(private readonly string $value) {}

            public function getField(): string
            {
                return 'run_id';
            }

            public function getValue(): mixed
            {
                return $this->value;
            }
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(?Filter $filter = null): array
    {
        $catalog = $this->createStub(WorkflowRunCatalogInterface::class);
        $catalog->method('listRuns')->willReturn(new WorkflowRunPage([
            new WorkflowRunDescription('server-run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, executionId: 'order/42'),
        ]));
        $factory = $this->createStub(RuntimeFactory::class);
        $factory->method('catalog')->willReturn($catalog);

        $listing = new ProcessListing('durable_process_listing', 'run_id', 'run_id', $factory);
        if (null !== $filter) {
            $listing->addFilter($filter);
        }
        $items = $listing->getData()['items'];
        self::assertIsArray($items);

        return array_values($items);
    }
}
