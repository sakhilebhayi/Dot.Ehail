<x-ops-layout title="Reports">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head"><div class="ops-head__text"><h1>Safety</h1><p class="ops-head__finding">Private reports from passengers and drivers. The reported person never sees them; reviewing records an outcome only.</p></div></header>
        @include('ops.safety.tabs', ['active' => 'reports'])
        <form class="ops-filter" method="GET" action="{{ route('ops.safety.reports') }}">
            <x-eh.select label="Status" name="status" :options="['open' => 'Open', 'reviewing' => 'Reviewing', 'actioned' => 'Actioned', 'dismissed' => 'Dismissed', 'all' => 'All']" :value="$status" />
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>
        <section class="ops-panel ops-panel--flush" aria-label="Reports">
            @if ($reports->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">No report in this list.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Report</th><th>About</th><th>From</th><th>Status</th><th>Review</th></tr></thead>
                    <tbody>@foreach ($reports as $r) @include('ops.safety.report-row') @endforeach</tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $reports])
            @endif
        </section>
    </div>
</x-ops-layout>
