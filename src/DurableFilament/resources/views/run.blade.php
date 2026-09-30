{{-- One run. Everything shown is RunDashboard::run(), rendered and never recomputed (DUR049). --}}
@php($t = fn (string $key, array $replace = []): string => __('durable-filament::durable.' . $key, $replace))
<x-filament-panels::page>
    <div>
        <x-filament::link :href="\Gplanchat\Durable\Filament\Pages\Runs::getUrl()">{{ $t('run.back') }}</x-filament::link>
    </div>

    @isset($backend)
        @include('durable-filament::backend', ['backend' => $backend])
    @endisset

    {{-- A backend that does not answer says so above; only one that answers can say it has no such run. --}}
    @if (null === $run && ($backend['available'] ?? true))
        <x-filament::section>
            <p>{{ $t('run.not_found') }}</p>
        </x-filament::section>
    @elseif (null !== $run)
        <x-filament::section>
            <dl>
                <dt><strong>{{ $t('run.workflow') }}</strong></dt>
                <dd>{{ $run['workflowName'] }}</dd>
                <dt><strong>{{ $t('run.execution') }}</strong></dt>
                <dd>
                    <code>{{ $run['executionId'] }}</code>
                    @if ($run['runId'] !== $run['executionId'])
                        <small>{{ $t('run.backend_run', ['id' => $run['runId']]) }}</small>
                    @endif
                </dd>
                <dt><strong>{{ $t('run.outcome') }}</strong></dt>
                <dd>
                    <x-filament::badge :color="\Gplanchat\Durable\Filament\StatusColor::of($run['status'])">{{ $t('status.' . $run['status']) }}</x-filament::badge>
                    {{-- What the run waits on, worded once by the core for every surface (#324, #447). --}}
                    @isset($run['waitingForWorker'])<span>{{ $run['waitingForWorker'] }}</span>@endisset
                    @isset($run['waitingOn'])<span>{{ $run['waitingOn'] }}</span>@endisset
                </dd>
            </dl>
        </x-filament::section>

        {{-- Read through NexusOperationCatalogInterface by a catalog that can hold them (#707); empty on a journal. --}}
        @include('durable-filament::nexus-operations', ['operations' => $run['nexusOperations'] ?? []])

        <x-filament::section :heading="$t('run.history')">
            @if ([] === $run['timeline']->actions)
                <p>{{ $t('run.no_history') }}</p>
            @else
                @include('durable-filament::frieze', ['timeline' => $run['timeline']])
                {{-- One block per action, grouped, ordered and timed by the core's RunTimeline. --}}
                @foreach ($run['timeline']->actions as $action)
                    <div style="margin-bottom: 1rem">
                        <h3><strong>{{ $action->label }}</strong> <small>{{ $action->durationLabel }}</small></h3>
                        <ul>
                            @foreach ($action->events as $mark)
                                <li>
                                    {{-- The time the event carries, in its own zone: the tooltip the core composes reads the same. --}}
                                    #{{ $mark->event->sequence }} {{ $mark->event->recordedAt->format('H:i:s.v') }} — {{ $mark->event->label }}
                                    @if ($mark->event->phase)
                                        <x-filament::badge color="gray">{{ $t('phase.' . $mark->event->phase->value) }}</x-filament::badge>
                                    @endif
                                    @if (null !== $mark->renderedDetails)
                                        <details><summary>…</summary><pre>{{ $mark->renderedDetails }}</pre></details>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
