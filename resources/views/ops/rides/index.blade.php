@php
    $statuses = collect(\App\Enums\RideStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
@endphp
<x-ops-layout title="All rides">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Rides</h1>
                <p class="ops-head__finding">{{ number_format($rides->total()) }} {{ \Illuminate\Support\Str::plural('ride', $rides->total()) }} match.</p>
            </div>
        </header>
        @include('ops.rides.tabs', ['active' => 'all'])

        <form class="ops-filter" method="GET" action="{{ route('ops.rides') }}" role="search" aria-label="Filter rides">
            <input type="hidden" name="view" value="all">
            <x-eh.field label="From" name="from" type="date" :value="$filters['from'] ?? null" />
            <x-eh.field label="To" name="to" type="date" :value="$filters['to'] ?? null" />
            <x-eh.select label="Status" name="status" :options="$statuses" placeholder="Any status" :value="$filters['status'] ?? ''" />
            <div class="eh-field eh-field--grow"><label class="eh-field__label" for="f-q">Ride number, person or address</label><x-dot.filter-search id="f-q" name="q" :value="$filters['q'] ?? ''" label="Ride number, person or address" placeholder="" maxlength="80" /></div>
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
            @if (array_filter(\Illuminate\Support\Arr::except($filters, ['view'])))<x-eh.button size="sm" variant="ghost" :href="route('ops.rides', ['view' => 'all'])">Clear</x-eh.button>@endif
        </form>

        <section class="ops-panel ops-panel--flush" aria-label="Rides">
            @if ($rides->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">No ride matches these filters.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Ride</th><th>Status</th><th>Passenger</th><th>Driver</th><th>Category</th><th class="num">Fare</th><th class="num">Requested</th></tr></thead>
                    <tbody>
                        @foreach ($rides as $ride)
                            <tr>
                                <td><a href="{{ route('ops.rides.show', $ride->id) }}">{{ $ride->id }}</a><span class="sub">{{ \Illuminate\Support\Str::limit((string) $ride->pickup_address, 36) }} to {{ \Illuminate\Support\Str::limit((string) $ride->dropoff_address, 36) }}</span></td>
                                <td><x-eh.status :status="$ride->status" /></td>
                                <td>{{ $ride->passenger?->name ?? '' }}</td>
                                <td>@if($ride->driver){{ $ride->driver->name }}@else<span class="ops-dash" aria-label="none">-</span>@endif</td>
                                <td>{{ \App\Domain\Business\CategoryNames::for($ride->category_code ?? $ride->vehicle_type) }}</td>
                                <td class="num">
                                    @if ($ride->final_fare_minor !== null)<x-eh.money :minor="$ride->final_fare_minor" :currency="$ride->currency ?? 'ZAR'" />
                                    @elseif ($ride->quoted_total_minor !== null)<x-eh.money :minor="$ride->quoted_total_minor" :currency="$ride->currency ?? 'ZAR'" muted /> <span class="sub">quoted</span>
                                    @elseif ($ride->final_fare !== null){{ \App\Services\Ehail\FareCalculator::format($ride->final_fare) }} <span class="sub">recorded</span>
                                    @else<span class="ops-dash" aria-label="none">-</span>@endif
                                </td>
                                <td class="num">{{ ($ride->requested_at ?? $ride->created_at)?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $rides])
            @endif
        </section>
    </div>
</x-ops-layout>
