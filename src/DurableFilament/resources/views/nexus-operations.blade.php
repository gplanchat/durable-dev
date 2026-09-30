{{-- A run's Nexus operations (#673): the one wait served by someone else. In flight is not a failure. --}}
@if ([] !== $operations)
    <x-filament::section :heading="__('durable-filament::durable.nexus.title')">
        <table style="width: 100%; border-collapse: collapse">
            <thead>
                <tr>
                    <th scope="col" style="text-align: start">{{ __('durable-filament::durable.nexus.endpoint') }}</th>
                    <th scope="col" style="text-align: start">{{ __('durable-filament::durable.nexus.service') }}</th>
                    <th scope="col" style="text-align: start">{{ __('durable-filament::durable.nexus.operation') }}</th>
                    <th scope="col" style="text-align: start">{{ __('durable-filament::durable.nexus.state') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($operations as $operation)
                    <tr>
                        <td><code>{{ $operation->endpoint }}</code></td>
                        <td>{{ $operation->service }}</td>
                        <td>{{ $operation->operation }}</td>
                        <td>
                            <x-filament::badge :color="match ($operation->state->value) { 'in_flight' => 'info', 'completed' => 'success', 'cancelled' => 'gray', default => 'danger' }">
                                {{ __('durable-filament::durable.nexus.states.' . $operation->state->value) }}
                            </x-filament::badge>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
@endif
