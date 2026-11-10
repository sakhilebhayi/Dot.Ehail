<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Trips" :line="$trips->total().' '.\Illuminate\Support\Str::plural('trip', $trips->total()).($filters['status'] ? ' '.$filters['status'] : '').'. Passengers appear by first name only.'">
    <div class="fl-filters">
        <x-eh.tabs label="Trip status" :active="$filters['status'] ?? 'all'" :items="[
            'all' => ['label' => 'All', 'href' => route('fleet.trips', array_filter(['driver' => $filters['driver'], 'vehicle' => $filters['vehicle']]))],
            'active' => ['label' => 'Under way', 'href' => route('fleet.trips', array_filter(['status' => 'active', 'driver' => $filters['driver'], 'vehicle' => $filters['vehicle']]))],
            'completed' => ['label' => 'Completed', 'href' => route('fleet.trips', array_filter(['status' => 'completed', 'driver' => $filters['driver'], 'vehicle' => $filters['vehicle']]))],
            'cancelled' => ['label' => 'Cancelled', 'href' => route('fleet.trips', array_filter(['status' => 'cancelled', 'driver' => $filters['driver'], 'vehicle' => $filters['vehicle']]))],
        ]" />
        <form method="GET" action="{{ route('fleet.trips') }}">
            @if ($filters['status'])<input type="hidden" name="status" value="{{ $filters['status'] }}">@endif
            <x-eh.select label="Driver" name="driver" :value="$filters['driver']" placeholder="All drivers" :options="$drivers->mapWithKeys(fn ($d) => [$d->user_id => $d->user?->name])->all()" />
            <x-eh.select label="Vehicle" name="vehicle" :value="$filters['vehicle']" placeholder="All vehicles" :options="$vehicles->pluck('plate_number', 'id')->all()" />
            <x-eh.button type="submit" variant="secondary" size="sm">Show</x-eh.button>
        </form>
    </div>
    <section class="fl-panel fl-panel--flush" aria-label="Trips">
        @if ($trips->isEmpty())
            <div style="padding: var(--eh-space-4) var(--eh-space-5)"><x-eh.empty title="No trips here" :heading-level="2"><p style="margin:0">Trips your drivers take, or that are taken in your vehicles, are listed here.</p></x-eh.empty></div>
        @else
            <div class="fl-table-wrap">
                <table class="fl-table fl-table--stack">
                    <thead><tr><th scope="col">When</th><th scope="col">Status</th><th scope="col">Driver</th><th scope="col">Vehicle</th><th scope="col">Passenger</th><th scope="col" class="num">Distance</th><th scope="col" class="num">Fare</th></tr></thead>
                    <tbody>
                        @foreach ($trips as $trip)
                            <tr>
                                <td>{{ $trip['at']?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y H:i') }}<span class="fl-sub">Trip {{ $trip['id'] }}</span></td>
                                <td><x-eh.status :status="$trip['status']" /></td>
                                <td data-label="Driver">{{ $trip['driver'] }}</td>
                                <td data-label="Vehicle">{!! $trip['vehicle'] ? '<span class="fl-plate">'.e($trip['vehicle']).'</span>' : '<span class="fl-dash">&#8212;</span>' !!}</td>
                                <td data-label="Passenger">{{ $trip['passenger_first_name'] }}</td>
                                <td class="num" data-label="Distance">{!! $trip['distance_m'] !== null ? e(number_format($trip['distance_m'] / 1000, 1, '.', ' ')).' km' : '<span class="fl-dash">&#8212;</span>' !!}</td>
                                <td class="num" data-label="Fare">@if ($trip['fare_minor'] !== null)<x-eh.money :minor="$trip['fare_minor']" :currency="$trip['currency']" />@else<span class="fl-dash">&#8212;</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    @if ($trips->hasPages())
        <nav class="fl-inline" aria-label="Pages">
            @if ($trips->previousPageUrl())<x-eh.button :href="$trips->previousPageUrl()" variant="ghost" size="sm" icon="arrow-left">Newer</x-eh.button>@endif
            <span class="fl-panel__meta">Page {{ $trips->currentPage() }} of {{ $trips->lastPage() }}</span>
            @if ($trips->nextPageUrl())<x-eh.button :href="$trips->nextPageUrl()" variant="ghost" size="sm" icon-right="arrow-right">Older</x-eh.button>@endif
        </nav>
    @endif
</x-fleet.page>
