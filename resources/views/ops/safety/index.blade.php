<x-ops-layout title="Safety">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Safety</h1>
                <p class="ops-head__finding" @if($counts['unacknowledged'] > 0) data-tone="stop" @endif>
                    @if ($counts['active'] === 0) No incident is open.
                    @else {{ $counts['active'] }} open {{ \Illuminate\Support\Str::plural('incident', $counts['active']) }}; {{ $counts['unacknowledged'] }} not acknowledged{{ $counts['overdue'] > 0 ? ', '.$counts['overdue'].' past the escalation timer' : '' }}.
                    @endif
                </p>
            </div>
        </header>
        @include('ops.safety.tabs', ['active' => 'incidents'])
        <dl class="ops-figures" aria-label="Safety now">
            <div data-tone="{{ $counts['critical'] > 0 ? 'stop' : '' }}"><dt>Critical, open</dt><dd>{{ $counts['critical'] }}</dd></div>
            <div data-tone="{{ $counts['unacknowledged'] > 0 ? 'stop' : '' }}"><dt>Not acknowledged</dt><dd>{{ $counts['unacknowledged'] }}</dd></div>
            <div data-tone="{{ $counts['overdue'] > 0 ? 'stop' : '' }}"><dt title="Open incidents past their escalation time (critical 2 min, high 5, medium 15, low 60)">Past the timer</dt><dd>{{ $counts['overdue'] }}</dd></div>
            <div><dt><a href="{{ route('ops.safety.check-ins') }}">Check-ins waiting</a></dt><dd>{{ $counts['checkins'] }}</dd></div>
            <div><dt><a href="{{ route('ops.safety.reports') }}">Reports to review</a></dt><dd>{{ $counts['reports'] }}</dd></div>
        </dl>
        <form class="ops-filter" method="GET" action="{{ route('ops.safety') }}">
            <x-eh.select label="Incidents" name="status" :options="['active' => 'Open now', 'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All']" :value="$status" />
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>
        <section class="ops-panel ops-panel--flush" aria-label="Incidents">
            @if ($incidents->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">{{ $status === 'active' ? 'No incident is open.' : 'No incident in this list.' }}</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Incident</th><th>Severity and state</th><th>Ride</th><th>Owner</th><th class="num">Opened</th><th class="num">Next escalation</th></tr></thead>
                    <tbody>
                        @foreach ($incidents as $incident)
                            <tr>
                                <td><a href="{{ route('ops.safety.show', $incident->id) }}">{{ $incident->reference }}</a><span class="sub">{{ $incident->kind->label() }} · raised by {{ $incident->reporter_role ?? 'system' }}{{ $incident->escalation_level ? ' · level '.$incident->escalation_level : '' }}</span></td>
                                <td>@include('ops.partials.incident-badges')</td>
                                <td>@if($incident->ride_id)Ride {{ $incident->ride_id }}<span class="sub">{{ \Illuminate\Support\Str::limit((string) $incident->ride?->pickup_address, 30) }}</span>@else<span class="ops-dash">no ride</span>@endif</td>
                                <td>{{ $incident->owner?->name ?? 'Nobody' }}</td>
                                <td class="num"><span class="ops-timer" data-tone="calm" data-ops-since="{{ $incident->created_at->toIso8601String() }}" data-ops-warn="0">{{ \App\Domain\Ops\Support\Durations::short((int) $incident->created_at->diffInSeconds(now(), true)) }}</span><span class="sub">{{ $incident->created_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i') }}</span></td>
                                <td class="num">@if($incident->next_escalation_at && in_array($incident->status->value, ['open', 'escalated'], true))<span class="ops-timer" data-ops-due="{{ $incident->next_escalation_at->toIso8601String() }}" data-ops-warn="60" data-tone="{{ $incident->next_escalation_at->isPast() ? 'stop' : 'caution' }}">{{ $incident->next_escalation_at->isPast() ? 'overdue' : 'due '.$incident->next_escalation_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i') }}</span>@else<span class="ops-dash">-</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $incidents])
            @endif
        </section>
    </div>
</x-ops-layout>
