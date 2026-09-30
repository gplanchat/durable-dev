<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Plugin\Grid;

use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Sylius\Component\Grid\Data\DataProviderInterface;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\Parameters;
use Sylius\Component\Grid\Provider\GridProviderInterface;
use Sylius\Component\Grid\View\GridViewFactoryInterface;

/**
 * The run list as a sylius/grid-bundle grid (#383, slice B): the grid's data provider over the run
 * catalog, and the grid view the list page renders.
 *
 * The only class of the plugin that names grid-bundle. The root analysis cannot see that bundle,
 * so this file is left out of it, and the Sylius bench covers it (the user's decision on #383).
 */
final readonly class SyliusRunGrid implements DataProviderInterface, RunGridViews
{
    public const GRID = 'gplanchat_durable_runs';

    public function __construct(
        private readonly RunDashboard $dashboard,
        private readonly GridProviderInterface $grids,
        private readonly GridViewFactoryInterface $views,
    ) {}

    public function getData(Grid $grid, Parameters $parameters): RunGridPage
    {
        $text = static fn(string $key): string => \is_string($value = $parameters->get($key)) ? trim($value) : '';
        $status = $text('status');
        $cursor = $text('cursor');

        return new RunGridPage($this->dashboard->listing(
            '' === $status ? 'all' : $status,
            '' === $cursor ? null : $cursor,
            new WorkflowRunFilter($text('workflowName'), $text('executionIdPrefix')),
        ));
    }

    public function view(array $query): array
    {
        $resources = $this->views->create($this->grids->get(self::GRID), new Parameters($query));
        $page = $resources->getData();

        return ['resources' => $resources, 'page' => $page instanceof RunGridPage ? $page : new RunGridPage([])];
    }
}
