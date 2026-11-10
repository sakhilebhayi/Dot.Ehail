@php
    $toneIcon = ['stop' => 'alert', 'caution' => 'warning', 'info' => 'info'];
    $areaRoute = ['drivers' => 'fleet.drivers.show', 'vehicles' => 'fleet.vehicles.show', 'maintenance' => 'fleet.vehicles.show'];
    $stop = collect($alerts)->where('severity', 'stop')->count();
    $caution = collect($alerts)->where('severity', 'caution')->count();
@endphp
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Alerts"
    :line="$alerts === [] ? 'Nothing needs attention right now.' : $stop.' '.\Illuminate\Support\Str::plural('alert', $stop).' stop a driver or vehicle working; '.$caution.' need action soon.'">
    <section class="fl-panel" aria-label="Alerts">
        @if ($alerts === [])
            <x-eh.empty title="All clear" :heading-level="2">
                <p style="margin:0">Documents in date, no service due within {{ config('ehail_fleet.alerts.service_due_days') }} days or {{ number_format(config('ehail_fleet.alerts.service_due_km'), 0, '.', ' ') }} km, no vehicle off the road, no driver offline with a fleet vehicle for more than {{ config('ehail_fleet.alerts.idle_assignment_hours') }} hours, and no unusual cancellations.</p>
            </x-eh.empty>
        @else
            <ul class="fl-items">
                @foreach ($alerts as $alert)
                    <li>
                        <span class="fl-sev--{{ $alert['severity'] }}"><x-eh.icon :name="$toneIcon[$alert['severity']]" :label="['stop' => 'Blocking', 'caution' => 'Soon', 'info' => 'Note'][$alert['severity']]" /></span>
                        <div>
                            <p class="fl-items__title">{{ $alert['title'] }}</p>
                            <p class="fl-items__detail">{{ $alert['detail'] }}</p>
                        </div>
                        <p class="fl-items__who" style="text-align:right">
                            @if ($alert['link'] && isset($areaRoute[$alert['link']['area']]))
                                <a class="eh-link" href="{{ route($areaRoute[$alert['link']['area']], $alert['link']['id']) }}">{{ $alert['subject'] }}</a>
                            @else
                                {{ $alert['subject'] }}
                            @endif
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
    <p class="fl-note">Alerts are worked out from the records each time this page opens: verification documents, maintenance records, who is online and the ride history. Thresholds: documents within {{ config('ehail_fleet.alerts.document_expiry_days') }} days of expiry; at least {{ config('ehail_fleet.alerts.cancellation_min_count') }} cancellations that are {{ intdiv(config('ehail_fleet.alerts.cancellation_min_share_bp'), 100) }}% or more of a driver's finished rides in {{ config('ehail_fleet.alerts.cancellation_window_days') }} days.</p>
</x-fleet.page>
