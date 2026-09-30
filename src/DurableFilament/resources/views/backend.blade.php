{{-- Backend health has three states (DUR049): unreachable, answering, and answering with a journal
     that does not outlive the request, whose empty list is the right answer. --}}
<x-filament::section>
    <x-filament::badge :color="! $backend['available'] ? 'danger' : (($backend['ephemeral'] ?? false) ? 'info' : 'success')">
        {{ $backend['message'] }}
    </x-filament::badge>
    {{-- Named only when there is a backend: naming a server that was never configured sends the
         operator down a false trail. --}}
    @isset($backend['name'])
        <p><small>{{ __('durable-filament::durable.backend.checked', ['name' => $backend['name'], 'time' => $backend['checkedAt']->format('Y-m-d H:i:s')]) }}</small></p>
    @endisset
</x-filament::section>
