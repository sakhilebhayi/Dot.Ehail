<x-ops-layout title="Cancellation patterns">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head"><div class="ops-head__text"><h1>Safety</h1><p class="ops-head__finding">Accounts flagged hourly for {{ config('ehail_safety.cancellations.passenger_threshold', 5) }} or more cancellations after a driver accepted, within {{ config('ehail_safety.cancellations.window_days', 7) }} days. A flag never suspends anyone; you decide.</p></div></header>
        @include('ops.safety.tabs', ['active' => 'patterns'])
        <section class="ops-panel ops-panel--flush" aria-label="Flagged accounts">
            @if ($flags->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">No account has been flagged.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Flag</th><th>About</th><th>From</th><th>Status</th><th>Review</th></tr></thead>
                    <tbody>@foreach ($flags as $r) @include('ops.safety.report-row') @endforeach</tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $flags])
            @endif
        </section>
    </div>
</x-ops-layout>
