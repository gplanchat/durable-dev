{{-- The run list. Only Blade components Filament 3 and 4 both ship (see Pages\DurablePage). --}}
@php
    $t = fn (string $key, array $replace = []): string => __('durable-filament::durable.' . $key, $replace);
    $runUrl = fn (string $id): string => \Gplanchat\Durable\Filament\Pages\Run::getUrl(['executionId' => $id]);
    $listUrl = fn (array $query = []): string => \Gplanchat\Durable\Filament\Pages\Runs::getUrl(array_filter($query + ['workflowName' => $filters['workflowName'], 'executionIdPrefix' => $filters['executionIdPrefix']], fn ($v) => null !== $v && '' !== $v));
@endphp
<x-filament-panels::page>
    @include('durable-filament::backend', ['backend' => $backend])

    @if ($backend['available'])
        @if ($filters['workflowNameAvailable'] || $filters['executionIdPrefixAvailable'])
            <x-filament::section>
                <form method="get" action="{{ \Gplanchat\Durable\Filament\Pages\Runs::getUrl() }}" style="display: flex; gap: .75rem; align-items: end; flex-wrap: wrap">
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
        @endif

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
                <table style="width: 100%; text-align: start; border-collapse: collapse">
                    <thead>
                        <tr>
                            <th scope="col" style="text-align: start">{{ $t('runs.execution') }}</th>
                            <th scope="col" style="text-align: start">{{ $t('runs.workflow') }}</th>
                            <th scope="col" style="text-align: start">{{ $t('runs.status') }}</th>
                            <th scope="col" style="text-align: start">{{ $t('runs.started_at') }}</th>
                            <th scope="col" style="text-align: start">{{ $t('runs.notes') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($runs as $run)
                            <tr>
                                <td><x-filament::link :href="$runUrl($run['executionId'])"><code>{{ $run['executionId'] }}</code></x-filament::link></td>
                                <td>{{ $run['workflowName'] }}</td>
                                <td><x-filament::badge :color="\Gplanchat\Durable\Filament\StatusColor::of($run['status'])">{{ $t('status.' . $run['status']) }}</x-filament::badge></td>
                                {{-- An absent fact in a table is an em dash (DUR049). --}}
                                <td>{{ isset($run['startedAt']) ? $run['startedAt']->format('Y-m-d H:i:s') : '—' }}</td>
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
