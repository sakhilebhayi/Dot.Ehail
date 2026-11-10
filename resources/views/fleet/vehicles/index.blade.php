@php
    $stateLabel = ['online' => 'Online', 'on_trip' => 'On a trip', 'paused' => 'Paused'];
    $tabs = ['all' => 'In the register', 'fleet' => 'Held by the fleet', 'drivers' => "Drivers' own", 'retired' => 'Retired'];
@endphp
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Vehicles"
    :line="$vehicles->count().' '.\Illuminate\Support\Str::plural('vehicle', $vehicles->count()).' '.\Illuminate\Support\Str::lower($tabs[$show]).'.'">
    <x-slot:actions>
        @if ($canWrite)<x-eh.button :href="route('fleet.vehicles.create')" icon="plus">Add vehicle</x-eh.button>@endif
    </x-slot:actions>

    <x-eh.tabs label="Vehicle lists" :active="$show" :items="collect($tabs)->map(fn ($label, $key) => ['label' => $label, 'href' => route('fleet.vehicles', $key === 'all' ? [] : ['show' => $key])])->all()" />

    <section class="fl-panel fl-panel--flush" aria-label="Vehicles">
        @if ($vehicles->isEmpty())
            <div style="padding: var(--eh-space-4) var(--eh-space-5)">
                <x-eh.empty :title="$show === 'retired' ? 'No retired vehicles' : 'No vehicles here'" :heading-level="2">
                    <p style="margin:0">{{ $show === 'drivers' ? "A driver's own car appears here when a driver who owns one joins the fleet." : 'Add a vehicle the fleet holds; you can then allow drivers to drive it and give it to one of them.' }}</p>
                </x-eh.empty>
            </div>
        @else
            <div class="fl-table-wrap">
                <table class="fl-table fl-table--stack">
                    <thead><tr><th scope="col">Plate</th><th scope="col">Vehicle</th><th scope="col">Class</th><th scope="col">Driver</th><th scope="col">State</th><th scope="col" class="num">Odometer</th><th scope="col">Next service</th></tr></thead>
                    <tbody>
                        @foreach ($vehicles as $v)
                            @php($next = $nextService($v))
                            <tr>
                                <td><a class="fl-plate" href="{{ route('fleet.vehicles.show', $v) }}">{{ $v->plate_number }}</a></td>
                                <td>{{ $v->year }} {{ $v->make }} {{ $v->model }}<span class="fl-sub">{{ $v->color }}, {{ $v->seats }} seats{{ $v->owned_by === 'driver' ? ", driver's own" : '' }}</span></td>
                                <td data-label="Class">{{ $v->type === 'suv' ? 'SUV' : ucfirst($v->type) }}</td>
                                <td data-label="Driver">{!! $v->driverProfile?->user?->name ? e($v->driverProfile->user->name) : '<span class="fl-dash">Nobody</span>' !!}</td>
                                <td class="fl-span">
                                    @if ($v->retired_at)<x-eh.badge>Retired</x-eh.badge>
                                    @elseif ($v->out_of_service_at)<x-eh.badge tone="stop">Out of service</x-eh.badge>
                                    @elseif ($working->has($v->id))<x-eh.badge tone="go">{{ $stateLabel[$working[$v->id]] ?? 'Working' }}</x-eh.badge>
                                    @elseif ($v->driver_profile_id === null)<x-eh.badge>No driver</x-eh.badge>
                                    @else<x-eh.badge tone="info">Offline</x-eh.badge>@endif
                                </td>
                                <td class="num" data-label="Odometer">{!! $v->odometer_km !== null ? e(number_format($v->odometer_km, 0, '.', ' ')).' km' : '<span class="fl-dash">&#8212;</span>' !!}</td>
                                <td data-label="Service">
                                    @if ($next['on'] || $next['km'])
                                        <span class="{{ $next['overdue'] ? 'fl-sev--stop' : ($next['due_soon'] ? 'fl-sev--caution' : '') }}">{{ $next['on']?->format('j M Y') }}{{ $next['on'] && $next['km'] ? ', ' : '' }}{{ $next['km'] ? number_format($next['km'], 0, '.', ' ').' km' : '' }}</span>
                                    @else<span class="fl-dash">Not recorded</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-fleet.page>
