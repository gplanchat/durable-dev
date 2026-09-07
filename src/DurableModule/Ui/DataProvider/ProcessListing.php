<?php

declare(strict_types=1);

namespace Gplanchat\DurableModule\Ui\DataProvider;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\DurableModule\Runtime\RuntimeFactory;
use Magento\Framework\Api\Filter;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * The source of the standard grid, for data that is not an SQL collection.
 *
 * `AbstractDataProvider` is the documented escape hatch: it implements the contract's fifteen
 * methods over a collection, and three of them are overridden when there is no collection. That is
 * what gives the admin chrome — columns, sorting, paging, bookmarks, export — without inventing a
 * table whose state would only be a stale copy of the cluster.
 *
 * ⚠ **Paging is the point of friction, and it is bounded rather than hidden.** The grid pages by
 * offset (`setLimit($offset, $size)`); the cluster pages by **continuation cursor**. The two do not
 * translate into one another without state. So this provider reads a bounded **window** and pages
 * inside it.
 *
 * The size of that window lives on {@see RuntimeFactory::OBSERVATION_WINDOW}, and not here: the
 * detail screen reads the same one, and two distinct literals made it possible to be listed here
 * and not found there. It is **told to the operator** by the banner above the grid — a bounded
 * window that does not announce itself is discovered through an execution that is missing.
 */
/*
 * Not `final`: the container instantiates it, so it generates an `Interceptor` extending it.
 */
class ProcessListing extends AbstractDataProvider
{
    /**
     * How a fact **this execution** does not have is rendered, in a grid with fixed columns. The
     * same as the detail screen's, and that is the whole point of naming it.
     */
    private const ABSENT = '—';

    /** @var array<string, list<string>|string> */
    private array $filters = [];

    private int $offset = 0;

    private int $size = 20;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private readonly RuntimeFactory $runtimeFactory,
        array $meta = [],
        array $data = [],
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        $runs = $this->runtimeFactory->catalog()->listRuns(limit: RuntimeFactory::OBSERVATION_WINDOW)->runs;

        $runs = $this->applyFilters($runs);

        $total = \count($runs);
        $window = \array_slice($runs, $this->offset, $this->size);

        return [
            'totalRecords' => $total,
            'items' => array_map(static fn(WorkflowRunDescription $run): array => [
                'run_id' => $run->runId,
                'workflow_name' => $run->workflowName,
                'status' => $run->status->value,
                // ⚠ An em dash, not an empty string. A running execution has no end date, and the
                // column exists for all the others: an empty cell reads as a rendering that
                // failed, where the dash says "nothing here". It is the opposite of the fact the
                // backend has **no notion of** — that one has no column at all.
                'started_at' => $run->startedAt?->format('Y-m-d H:i:s') ?? self::ABSENT,
                'ended_at' => $run->endedAt?->format('Y-m-d H:i:s') ?? self::ABSENT,
            ], $window),
        ];
    }

    /**
     * The filter applies to what the window contains, not to the cluster: Temporal's visibility
     * has a query language of its own, and translating the grid's filters into it would be a
     * surface in its own right. Saying which of the two is filtered beats letting someone believe
     * otherwise.
     */
    public function addFilter(Filter $filter): void
    {
        // ⚠ `Filter::getValue()` is annotated `@return string` upstream, and that is false: the
        // status filter is a `ui-select`, which returns an **array** as soon as the operator ticks
        // more than one box, and a string when they tick only one. Both forms were measured here.
        // The annotation says what actually arrives, rather than silencing the analysis.
        /** @var mixed $value */
        $value = $filter->getValue();
        $this->filters[$filter->getField()] = \is_array($value)
            ? array_values(array_map('strval', $value))
            : (string) $value;
    }

    /**
     * @param list<WorkflowRunDescription> $runs
     *
     * @return list<WorkflowRunDescription>
     */
    private function applyFilters(array $runs): array
    {
        foreach ($this->filters as $field => $value) {
            $runs = match ($field) {
                'workflow_name' => array_values(array_filter(
                    $runs,
                    static fn(WorkflowRunDescription $run): bool => str_contains(
                        mb_strtolower($run->workflowName),
                        mb_strtolower((string) $value),
                    ),
                )),
                'run_id' => array_values(array_filter(
                    $runs,
                    static fn(WorkflowRunDescription $run): bool => str_contains($run->runId, (string) $value),
                )),
                'status' => array_values(array_filter(
                    $runs,
                    static fn(WorkflowRunDescription $run): bool => \in_array(
                        $run->status->value,
                        (array) $value,
                        true,
                    ),
                )),
                default => $runs,
            };
        }

        return $runs;
    }

    public function addOrder($field, $direction): void
    {
        // The cluster already returns the most recent executions first, and the catalog
        // guarantees it by re-sorting. Sorting by column would mean sorting the window, which
        // would lie as soon as the window is smaller than the total.
    }

    public function setLimit($offset, $size): void
    {
        $this->offset = max(0, (int) $offset);
        $this->size = (int) $size > 0 ? (int) $size : 20;
    }
}
