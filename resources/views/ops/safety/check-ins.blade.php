<x-ops-layout title="Check-ins">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head"><div class="ops-head__text"><h1>Safety</h1><p class="ops-head__finding">"Are you OK?" prompts sent after a route deviation or an unusual stop. Silence past the deadline raises an incident for operations.</p></div></header>
        @include('ops.safety.tabs', ['active' => 'check-ins'])
        <form class="ops-filter" method="GET" action="{{ route('ops.safety.check-ins') }}">
            <x-eh.select label="Show" name="status" :options="['pending' => 'Waiting for an answer', 'all' => 'All']" :value="$status" />
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>
        <section class="ops-panel ops-panel--flush" aria-label="Check-ins">
            @if ($checkIns->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">{{ $status === 'pending' ? 'No check-in is waiting for an answer.' : 'No check-in has been sent.' }}</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Ride</th><th>Asked</th><th>Why</th><th>Answer</th><th class="num">Deadline</th><th>Incident</th></tr></thead>
                    <tbody>
                        @foreach ($checkIns as $c)
                            <tr>
                                <td>@can('ops.rides.view')<a href="{{ route('ops.rides.show', $c->ride_id) }}">Ride {{ $c->ride_id }}</a>@else Ride {{ $c->ride_id }} @endcan</td>
                                <td>{{ $c->user?->name }} <span class="sub">{{ $c->role }}</span></td>
                                <td>{{ str_replace('_', ' ', $c->trigger) }}</td>
                                <td>{{ str_replace('_', ' ', $c->status) }}@if($c->responded_at)<span class="sub">{{ $c->responded_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i:s') }}</span>@endif</td>
                                <td class="num">@if($c->status === 'pending' && $c->respond_by)<span class="ops-timer" data-ops-due="{{ $c->respond_by->toIso8601String() }}" data-ops-warn="60">{{ $c->respond_by->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i:s') }}</span>@else{{ $c->respond_by?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i') ?? '-' }}@endif</td>
                                <td>@if($c->safety_incident_id)<a href="{{ route('ops.safety.show', $c->safety_incident_id) }}">Open</a>@else<span class="ops-dash">-</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $checkIns])
            @endif
        </section>
    </div>
</x-ops-layout>
