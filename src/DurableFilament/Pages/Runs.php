<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Filament\Pages;

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

        return app(RunDashboard::class)->listing(
            'all',
            '' === $this->cursor ? null : $this->cursor,
            $filter->isEmpty() ? null : $filter,
        );
    }
}
