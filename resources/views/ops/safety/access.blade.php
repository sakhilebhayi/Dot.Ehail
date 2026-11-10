<x-ops-layout title="Who viewed what">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head"><div class="ops-head__text"><h1>Safety</h1><p class="ops-head__finding">Every time an operator opened a ride, a driver record, an incident, a case or an identity document. Views of the same record by the same person are recorded once per 10 minutes.</p></div></header>
        @include('ops.safety.tabs', ['active' => 'access'])
        <form class="ops-filter" method="GET" action="{{ route('ops.safety.access') }}">
            <x-eh.select label="Record" name="what" :options="['all' => 'Everything', 'documents' => 'Identity and vehicle documents', 'incidents' => 'Incidents', 'drivers' => 'Driver records', 'rides' => 'Rides', 'cases' => 'Support cases']" :value="$what" />
            <x-eh.field label="Operator user number" name="actor" type="number" :value="$actor" :optional="true" />
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>
        <section class="ops-panel ops-panel--flush" aria-label="Access log">
            @if ($rows->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">Nothing recorded for this filter.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table ops-table--compact">
                    <thead><tr><th>Who</th><th>Opened</th><th>Record</th><th class="num">When</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row->actor?->name ?? 'unknown' }}<span class="sub">user {{ $row->actor_user_id }}</span></td>
                                <td>{{ str_replace(['ops.viewed.', '.viewed', '.opened', '_'], ['', ' opened', ' opened', ' '], $row->action) }}</td>
                                <td>{{ class_basename($row->subject_type) }} {{ $row->subject_id }}</td>
                                <td class="num">{{ $row->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i:s') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $rows])
            @endif
        </section>
    </div>
</x-ops-layout>
