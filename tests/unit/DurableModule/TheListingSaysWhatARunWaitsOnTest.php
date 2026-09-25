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
 * #324: the catalogue records what a suspended run waits on; the grid is where an operator asks
 * why a run is not moving.
 */
final class TheListingSaysWhatARunWaitsOnTest extends TestCase
{
    public function testASuspendedRunCarriesItsWait(): void
    {
        $rows = $this->rows(new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, waitingOn: 'signal approve'));

        self::assertSame('signal approve', $rows[0]['waiting_on']);
    }

    public function testARunWithNoRecordedWaitShowsADash(): void
    {
        // A dash, as for a missing end date: an empty cell reads as a rendering that failed.
        $rows = $this->rows(new WorkflowRunDescription('run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Completed));

        self::assertSame('—', $rows[0]['waiting_on']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(WorkflowRunDescription $run): array
    {
        $catalog = $this->createStub(WorkflowRunCatalogInterface::class);
        $catalog->method('listRuns')->willReturn(new WorkflowRunPage([$run]));
        $factory = $this->createStub(RuntimeFactory::class);
        $factory->method('catalog')->willReturn($catalog);

        $items = (new ProcessListing('durable_process_listing', 'run_id', 'run_id', $factory))->getData()['items'];
        self::assertIsArray($items);

        return array_values($items);
    }
}
