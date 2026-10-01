{{-- The run list. Only Blade components Filament 3 and 4 both ship (see Pages\DurablePage). --}}
@php
    $t = fn (string $key, array $replace = []): string => __('durable-filament::durable.' . $key, $replace);
    $runUrl = fn (string $id): string => \Gplanchat\Durable\Filament\Pages\Run::getUrl(['executionId' => $id]);
    // Read before the counters loop, which reuses $status as its own variable.
    $selectedOutcome = \Gplanchat\Durable\Observation\WorkflowRunStatus::tryFrom($status)?->value ?? '';
    $listUrl = fn (array $query = []): string => \Gplanchat\Durable\Filament\Pages\Runs::getUrl(array_filter($query + ['status' => $selectedOutcome, 'workflowName' => $filters['workflowName'], 'executionIdPrefix' => $filters['executionIdPrefix']], fn ($v) => null !== $v && '' !== $v));
@endphp
<x-filament-panels::page>
    @include('durable-filament::backend', ['backend' => $backend])

    @if ($backend['available'])
        {{-- Every catalog filters by outcome; the two text filters show only where it can apply them. --}}
        <x-filament::section>
            <form method="get" action="{{ \Gplanchat\Durable\Filament\Pages\Runs::getUrl() }}" style="display: flex; gap: .75rem; align-items: end; flex-wrap: wrap">
                <label>
                    <span>{{ $t('filter.outcome') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select name="status">
                            @foreach (['' => 'all', 'running' => 'running', 'completed' => 'completed', 'failed' => 'failed', 'cancelled' => 'cancelled', 'continued_as_new' => 'continued_as_new'] as $value => $label)
                                <option value="{{ $value }}" @selected($value === $selectedOutcome)>{{ $t('status.' . $label) }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>
                @if ($filters['workflowNameAvailable'])
                    <label>
                        <span>{{ $t('filter.workflow_name') }}</span>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" name="workflowName" value="{{ $filters['workflowName'] }}" />
                        </x-filament::input.wrapper>
                    </label>
                @endif
                @if ($filters['executionIdPrefixAvailable'])
                    <label>
                        <span>{{ $t('filter.execution_id_prefix') }}</span>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" name="executionIdPrefix" value="{{ $filters['executionIdPrefix'] }}" />
                        </x-filament::input.wrapper>
                    </label>
                @endif
                <x-filament::button type="submit">{{ $t('filter.submit') }}</x-filament::button>
            </form>
        </x-filament::section>

        {{-- The counters cover the page on screen and say so (DUR049): a "Total" above twenty
             teaches that an application with five hundred runs has twenty. --}}
        <x-filament::section :heading="$t('outcomes_heading', ['count' => $kpis['total']])">
            <div style="display: flex; gap: .5rem; flex-wrap: wrap">
                @foreach (['running', 'completed', 'failed', 'cancelled', 'continued_as_new'] as $status)
                    <x-filament::badge :color="\Gplanchat\Durable\Filament\StatusColor::of($status)">{{ $t('status.' . $status) }} · {{ $kpis[$status] }}</x-filament::badge>
                @endforeach
                @isset($waitingForWorkerOnThisPage)
                    <x-filament::badge color="warning">{{ $t('kpi.waiting_for_worker') }} · {{ $waitingForWorkerOnThisPage }}</x-filament::badge>
                @endisset
            </div>
        </x-filament::section>

        <x-filament::section>
            @if ([] === $runs)
                <p>{{ $t('runs.empty') }}</p>
            @else
                {{-- The few rules a table needs, inline, so the list renders the same on Filament 3 and 4
                     without Eloquent; the badge keeps its width, where "Completed" read "Complet…" (#850). --}}
                <style>
                    .durable-runs th, .durable-runs td { padding: .625rem .75rem; text-align: start; vertical-align: middle; border-bottom: 1px solid rgba(127, 127, 127, .2); }
                    .durable-runs th { font-size: .875rem; font-weight: 600; white-space: nowrap; }
                    .durable-runs tbody tr:last-child td { border-bottom: 0; }
                </style>
                <table class="durable-runs" style="width: 100%; border-collapse: collapse; font-size: .875rem">
                    <thead>
                        <tr>
                            <th scope="col">{{ $t('runs.execution') }}</th>
                            <th scope="col">{{ $t('runs.workflow') }}</th>
                            <th scope="col">{{ $t('runs.status') }}</th>
                            <th scope="col">{{ $t('runs.started_at') }}</th>
                            <th scope="col">{{ $t('runs.notes') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td><x-filament::link :href="$runUrl($run['executionId'])"><code>{{ $run['executionId'] }}</code></x-filament::link></td>
                                <td>{{ $run['workflowName'] }}</td>
                                <td style="white-space: nowrap"><x-filament::badge :color="\Gplanchat\Durable\Filament\StatusColor::of($run['status'])" style="min-width: max-content">{{ $t('status.' . $run['status']) }}</x-filament::badge></td>
                                {{-- An absent fact in a table is an em dash (DUR049). --}}
                                <td style="white-space: nowrap">{{ isset($run['startedAt']) ? $run['startedAt']->format('Y-m-d H:i:s') : '—' }}</td>
                                <td>{{ implode(' · ', array_filter([$run['waitingForWorker'] ?? null, $run['waitingOn'] ?? null])) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            {{-- Forward only, with the first page as the way back (#383). --}}
            <div style="display: flex; gap: .75rem; margin-top: 1rem">
                @if (null !== $pagination['cursor'])
                    <x-filament::link :href="$listUrl()">{{ $t('pagination.first') }}</x-filament::link>
                @endif
                @if ($pagination['hasNext'])
                    <x-filament::link :href="$listUrl(['cursor' => $pagination['nextCursor']])">{{ $t('pagination.next') }}</x-filament::link>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
