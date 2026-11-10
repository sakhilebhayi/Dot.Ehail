<x-business-layout title="Trips" :scope="$account->displayName()">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    <div class="bz">
        <div class="bz-title">
            <div class="bz-title__text">
                <h1 class="eh-h1" style="margin:0">Trips</h1>
                <p class="bz-title__sub">{{ $totals['count'] }} {{ \Illuminate\Support\Str::plural('trip', $totals['count']) }} match, {{ \App\Support\Ui\MoneyFormatter::format($totals['charged'], $account->currency) }} billed. {{ $seesAll ? 'Everyone on the account.' : 'Only rides you took or booked.' }}</p>
            </div>
            <div class="bz-title__actions"><x-eh.button variant="secondary" icon="download" :href="route('business.trips.csv', request()->query())">Download CSV</x-eh.button></div>
        </div>

        <form class="bz-panel bz-filters" method="GET" action="{{ route('business.trips') }}" role="search" aria-label="Filter trips">
            @if ($seesAll)
                <x-eh.select label="Person" name="person" :options="$people->mapWithKeys(fn ($p) => [$p->id => $p->displayName()])->all()" :value="$filters['person']" placeholder="Everyone" />
                <x-eh.select label="Department" name="department" :options="$departments->mapWithKeys(fn ($d) => [$d->id => $d->name])->all()" :value="$filters['department']" placeholder="All departments" />
            @endif
            <x-eh.field label="From" name="from" type="date" :value="$filters['from']" />
            <x-eh.field label="To" name="to" type="date" :value="$filters['to']" />
            <x-eh.select label="Billing" name="state" :options="['charged' => 'Billed', 'booked' => 'Held, not billed yet', 'released' => 'Not billed']" :value="$filters['state']" placeholder="Any" />
            <div class="bz-filters__actions">
                <x-eh.button type="submit" icon="filter">Filter</x-eh.button>
                @if (array_filter($filters))<a class="eh-link" href="{{ route('business.trips') }}">Clear</a>@endif
            </div>
        </form>

        <section class="bz-panel bz-panel--flush" aria-label="Trips">
            @if ($trips->isEmpty())
                <div style="padding:var(--eh-space-5)"><p class="bz-hint">No trips match. @if (array_filter($filters))<a class="eh-link" href="{{ route('business.trips') }}">Show all trips</a>.@endif</p></div>
            @else
                <div class="bz-tablewrap"><table class="bz-table">
                    <thead><tr><th scope="col">When</th><th scope="col">Rider</th><th scope="col">Department</th><th scope="col">Purpose</th><th scope="col">Route</th><th scope="col">Ride</th><th scope="col" class="num">Amount</th></tr></thead>
                    <tbody>@foreach ($trips as $trip)@include('business.partials.trip-row', ['trip' => $trip])@endforeach</tbody>
                </table></div>
            @endif
        </section>
        @if ($trips->hasPages())
            <nav class="bz-title" aria-label="Pages">
                <p class="bz-hint">Page {{ $trips->currentPage() }} of {{ $trips->lastPage() }}</p>
                <div class="bz-title__actions">
                    @if ($trips->previousPageUrl())<x-eh.button variant="secondary" size="sm" icon="chevron-left" :href="$trips->previousPageUrl()">Newer</x-eh.button>@endif
                    @if ($trips->nextPageUrl())<x-eh.button variant="secondary" size="sm" icon-right="chevron-right" :href="$trips->nextPageUrl()">Older</x-eh.button>@endif
                </div>
            </nav>
        @endif
    </div>
</x-business-layout>
