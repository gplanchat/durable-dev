{{-- The frieze: one row per action, placed in time (DUR049). The seconds come from the core's
     RunTimeline; scaling them to a width is the one thing a host decides, and every interval gets
     the same minimum width so a short queue never draws wider than longer work. --}}
@php($scale = $timeline->span > 0 ? 100 / $timeline->span : 0)
<style>
    .durable-frieze-row { display: flex; align-items: center; gap: .75rem; margin-bottom: .3rem; }
    .durable-frieze-name { flex: 0 0 12rem; text-align: end; font-size: .82rem; line-height: 1.2; overflow-wrap: anywhere; }
    .durable-frieze-track { position: relative; flex: 1 1 auto; height: 1.4rem; border: 1px solid rgba(127, 127, 127, .35); border-radius: .375rem; }
    .durable-frieze-bar { position: absolute; top: .3rem; height: .8rem; min-width: .2rem; border-radius: .4rem; opacity: .35; background: #3b82f6; }
    .durable-frieze-mark { position: absolute; top: .4rem; width: .6rem; height: .6rem; margin-left: -.3rem; border-radius: 50%; background: #3b82f6; }
    .durable-frieze-bar.execution, .durable-frieze-mark.execution { background-color: #6b7280; }
    .durable-frieze-bar.nexus, .durable-frieze-mark.nexus { background-color: #f97316; }
    .durable-frieze-bar.waiting { opacity: .75; background-image: repeating-linear-gradient(45deg, rgba(255, 255, 255, .9) 0 1px, rgba(255, 255, 255, 0) 1px 3px); }
    .durable-frieze-bar.failed, .durable-frieze-mark.failed { background-color: #ef4444; opacity: .75; }
    .durable-frieze-took { flex: 0 0 5rem; font-size: .8rem; }
    .durable-frieze-key { display: flex; align-items: center; gap: .5rem; margin-top: .5rem; font-size: .8rem; }
    .durable-frieze-key .durable-frieze-bar { position: static; flex: 0 0 2.5rem; }
</style>
<div style="margin-bottom: 1.25rem">
    @foreach ($timeline->actions as $action)
        <div class="durable-frieze-row">
            <span class="durable-frieze-name" title="{{ $action->label }}">{{ $action->label }}</span>
            <div class="durable-frieze-track">
                @foreach ($action->segments as $segment)
                    <span class="durable-frieze-bar {{ $action->kind->value }}{{ $segment->waiting ? ' waiting' : '' }}{{ $segment->failed ? ' failed' : '' }}"
                          style="left: {{ number_format($segment->offset * $scale, 3, '.', '') }}%; width: {{ number_format($segment->duration * $scale, 3, '.', '') }}%"
                          title="{{ $segment->title }}"></span>
                @endforeach
                @foreach ($action->events as $mark)
                    <span class="durable-frieze-mark {{ $action->kind->value }}{{ $mark->event->failed ? ' failed' : '' }}"
                          style="left: {{ number_format($mark->offset * $scale, 3, '.', '') }}%" title="{{ $mark->title }}"></span>
                @endforeach
            </div>
            <span class="durable-frieze-took">{{ $action->durationLabel }}</span>
        </div>
    @endforeach
    <div class="durable-frieze-key"><span class="durable-frieze-bar waiting"></span>{{ __('durable-filament::durable.frieze.key_waiting') }}</div>
    <div class="durable-frieze-key"><span class="durable-frieze-bar failed"></span>{{ __('durable-filament::durable.frieze.key_failed') }}</div>
</div>
