<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Pages;

use Gplanchat\Durable\Filament\WorkerPresence;
use Gplanchat\Durable\Observation\RunDashboard;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
use Livewire\Attributes\Url;

/**
 * The runs, a page at a time, by the catalog's own cursor: forward, and back to the first page.
 *
 * Not a Filament table: on Filament 3 a table reads an Eloquent query and nothing else, and on
 * Filament 4 its paginator counts pages or builds a cursor from the rows. The catalog hands an
 * opaque cursor that only goes forward (#383). The list is therefore a Blade table, the same on
 * both lines, over {@see RunDashboard::listing()}.
 */
final class Runs extends DurablePage
{
    protected static ?string $slug = 'durable/runs';

    #[Url]
    public string $cursor = '';

    /** Named `status` in the URL, as on Sylius; not `$status`, which the view data already carries. */
    #[Url(as: 'status')]
    public string $outcome = '';

    #[Url]
    public string $workflowName = '';

    #[Url]
    public string $executionIdPrefix = '';

    public static function getNavigationLabel(): string
    {
        return (string) __('durable-filament::durable.navigation');
    }

    public function getTitle(): string
    {
        return (string) __('durable-filament::durable.runs.title');
    }

    protected function durableView(): string
    {
        return 'durable-filament::runs';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $filter = new WorkflowRunFilter($this->workflowName, $this->executionIdPrefix);

        $listing = app(RunDashboard::class)->listing(
            '' === $this->outcome ? 'all' : $this->outcome,
            '' === $this->cursor ? null : $this->cursor,
            $filter->isEmpty() ? null : $filter,
        );
        // A missing worker fails nothing: executions stop at their first task of its kind. Asked
        // only of a backend that answers and keeps runs outside this process, as on Sylius.
        $listing['workers'] = true === $listing['backend']['available'] && true !== ($listing['backend']['ephemeral'] ?? false)
            ? app(WorkerPresence::class)->rows()
            : [];

        return $listing;
    }
}
