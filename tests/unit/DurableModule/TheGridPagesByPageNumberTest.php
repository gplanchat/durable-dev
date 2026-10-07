<?php

declare(strict_types=1);

namespace unit\Gplanchat\DurableModule;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Gplanchat\DurableModule\Ui\DataProvider\ProcessListing;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fixture/magento-data-provider.php';

/**
 * #848: Magento's paging component calls `setLimit($page, $size)` with the number of the page, 1
 * for the first one, not with a row offset. Read as an offset, page 1 started at the second run
 * and page 2 repeated the end of page 1.
 */
final class TheGridPagesByPageNumberTest extends TestCase
{
    public function testThePageNumberOneStartsAtTheNewestRun(): void
    {
        self::assertSame($this->ids(1, 20), $this->executionIds(1));
    }

    public function testThePageNumberTwoContinuesWhereTheFirstStopped(): void
    {
        self::assertSame($this->ids(21, 25), $this->executionIds(2));
    }

    public function testTheTotalIsTheWholeWindowOnEveryPage(): void
    {
        self::assertSame(25, $this->data(2)['totalRecords']);
    }

    /**
     * @return list<string>
     */
    private function executionIds(int $page): array
    {
        $items = $this->data($page)['items'];
        self::assertIsArray($items);

        return array_values(array_map(static fn(array $row): string => $row['execution_id'], $items));
    }

    /**
     * @return list<string>
     */
    private function ids(int $from, int $to): array
    {
        return array_map(static fn(int $number): string => 'order/' . $number, range($from, $to));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(int $page): array
    {
        $descriptions = array_map(
            static fn(int $number): WorkflowRunDescription => new WorkflowRunDescription('run-' . $number, 'App\\OrderWorkflow', WorkflowRunStatus::Completed, executionId: 'order/' . $number),
            range(1, 25),
        );
        $catalog = $this->createStub(WorkflowRunCatalogInterface::class);
        $catalog->method('listRuns')->willReturn(new WorkflowRunPage($descriptions));
        $factory = $this->createStub(RuntimeFactory::class);
        $factory->method('catalog')->willReturn($catalog);

        $provider = new ProcessListing('durable_process_listing', 'run_id', 'run_id', $factory);
        $provider->setLimit($page, 20);

        return $provider->getData();
    }
}
