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
 * #815: the text filters of the grid follow the rule of the Sylius and Filament lists. The whole
 * workflow name, the start of the execution id, and both as typed: an operator who works on two
 * surfaces gets the same runs for the same input. The backend run id, which only this grid
 * offers, follows the rule of the execution id.
 */
final class TheGridFiltersFollowTheOtherSurfacesTest extends TestCase
{
    public function testTheWorkflowNameIsTheWholeName(): void
    {
        self::assertSame(['order/42', 'invoice/7'], $this->matching('workflow_name', 'App\\OrderWorkflow'));
        self::assertSame([], $this->matching('workflow_name', 'Order'), 'a part of the name is not the name');
    }

    public function testTheWorkflowNameIsCaseSensitive(): void
    {
        self::assertSame([], $this->matching('workflow_name', 'app\\orderworkflow'));
    }

    public function testTheExecutionIdIsMatchedFromItsStart(): void
    {
        self::assertSame(['order/42', 'order/43'], $this->matching('execution_id', 'order/4'));
        self::assertSame([], $this->matching('execution_id', 'rder/42'), 'the middle of an id is not its start');
    }

    public function testTheExecutionIdIsCaseSensitive(): void
    {
        self::assertSame([], $this->matching('execution_id', 'ORDER/'));
    }

    public function testTheBackendRunIdIsMatchedFromItsStart(): void
    {
        self::assertSame(['order/42', 'order/43'], $this->matching('run_id', 'server-run'));
        self::assertSame([], $this->matching('run_id', 'run-1'));
    }

    public function testThePercentAndTheUnderscoreAreTakenLiterally(): void
    {
        self::assertSame([], $this->matching('execution_id', 'order_'));
        self::assertSame([], $this->matching('execution_id', '%'));
    }

    /**
     * Magento's `Input` filter component turns a `like` condition into `%text%`, with `%` and `_`
     * escaped, before it reaches the data provider. A provider that compares the typed text would
     * then match nothing. An `eq` condition hands the text over as typed.
     */
    public function testTheTextColumnsAskMagentoForTheTypedTextAndNotForAPattern(): void
    {
        $listing = simplexml_load_file(\dirname(__DIR__, 3) . '/src/DurableModule/view/adminhtml/ui_component/durable_process_listing.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $listing);

        foreach (['workflow_name', 'execution_id', 'run_id'] as $column) {
            $filter = [];
            foreach ($listing->xpath("//columns/column[@name='$column']/argument[@name='data']/item[@name='config']/item[@name='filter']/item") ?: [] as $item) {
                $filter[(string) $item['name']] = trim((string) $item);
            }

            self::assertSame(['filterType' => 'text', 'conditionType' => 'eq'], $filter, $column);
            self::assertSame([], $listing->xpath("//columns/column[@name='$column']/settings/filter") ?: [], $column . ': the `text` shorthand means `like`');
        }
    }

    /**
     * @return list<string> the execution ids the grid lists under the filter
     */
    private function matching(string $field, string $value): array
    {
        $catalog = $this->createStub(WorkflowRunCatalogInterface::class);
        $catalog->method('listRuns')->willReturn(new WorkflowRunPage([
            new WorkflowRunDescription('server-run-1', 'App\\OrderWorkflow', WorkflowRunStatus::Running, executionId: 'order/42'),
            new WorkflowRunDescription('server-run-2', 'App\\RefundWorkflow', WorkflowRunStatus::Running, executionId: 'order/43'),
            new WorkflowRunDescription('other-run-3', 'App\\OrderWorkflow', WorkflowRunStatus::Running, executionId: 'invoice/7'),
        ]));
        $factory = $this->createStub(RuntimeFactory::class);
        $factory->method('catalog')->willReturn($catalog);

        $listing = new ProcessListing('durable_process_listing', 'run_id', 'run_id', $factory);
        $listing->addFilter(new class ($field, $value) extends Filter {
            public function __construct(private readonly string $field, private readonly string $value) {}

            public function getField(): string
            {
                return $this->field;
            }

            public function getValue(): mixed
            {
                return $this->value;
            }
        });
        $items = $listing->getData()['items'];
        self::assertIsArray($items);

        return array_values(array_map(static fn(array $row): string => $row['execution_id'], $items));
    }
}
