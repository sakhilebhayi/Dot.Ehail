@php
    $overdue = $rows->filter(fn ($r) => $r['next']['overdue'])->count();
    $soon = $rows->filter(fn ($r) => $r['next']['due_soon'])->count();
    $out = $rows->filter(fn ($r) => $r['vehicle']->out_of_service_at !== null)->count();
@endphp
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Maintenance"
    :line="$rows->isEmpty() ? 'No vehicles yet.' : $overdue.' '.\Illuminate\Support\Str::plural('service', $overdue).' overdue, '.$soon.' due within '.config('ehail_fleet.alerts.service_due_days').' days or '.number_format(config('ehail_fleet.alerts.service_due_km'), 0, '.', ' ').' km, '.$out.' out of service.'">

    <section class="fl-panel fl-panel--flush" aria-labelledby="due-title">
        <div class="fl-panel__head"><h2 id="due-title">Service due</h2><span class="fl-panel__meta">From each vehicle's latest record that sets the next service</span></div>
        @if ($rows->isEmpty())
            <div style="padding: 0 var(--eh-space-5) var(--eh-space-4)"><x-eh.empty title="No vehicles" :heading-level="3"><p style="margin:0">Add a vehicle to keep its service history here.</p></x-eh.empty></div>
        @else
            <div class="fl-table-wrap">
                <table class="fl-table fl-table--stack">
                    <thead><tr><th scope="col">Plate</th><th scope="col">Driver</th><th scope="col">State</th><th scope="col" class="num">Odometer</th><th scope="col">Next service</th><th scope="col">Last record</th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($v = $row['vehicle'])
                            @php($n = $row['next'])
                            <tr>
                                <td><a class="fl-plate" href="{{ route('fleet.vehicles.show', $v) }}#maint-title">{{ $v->plate_number }}</a><span class="fl-sub">{{ $v->make }} {{ $v->model }}</span></td>
                                <td data-label="Driver">{!! $v->holder?->user?->name ? e($v->holder->user->name) : '<span class="fl-dash">Nobody</span>' !!}</td>
                                <td class="fl-span">@if ($v->out_of_service_at)<x-eh.badge tone="stop">Out of service</x-eh.badge><span class="fl-sub">{{ $v->out_of_service_reason }}</span>@else<x-eh.badge tone="go">In service</x-eh.badge>@endif</td>
                                <td class="num" data-label="Odometer">{!! $v->odometer_km !== null ? e(number_format($v->odometer_km, 0, '.', ' ')).' km' : '<span class="fl-dash">&#8212;</span>' !!}</td>
                                <td data-label="Next">
                                    @if ($n['on'] || $n['km'])
                                        <span class="fl-nowrap {{ $n['overdue'] ? 'fl-sev--stop' : ($n['due_soon'] ? 'fl-sev--caution' : '') }}">{{ $n['on']?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y') }}{{ $n['on'] && $n['km'] ? ' or ' : '' }}{{ $n['km'] ? number_format($n['km'], 0, '.', ' ').' km' : '' }}</span>
                                        <span class="fl-sub">{{ $n['overdue'] ? 'Overdue' : ($n['due_soon'] ? 'Due soon' : '') }}{{ $n['km_left'] !== null ? ($n['overdue'] || $n['due_soon'] ? ', ' : '').number_format(abs($n['km_left']), 0, '.', ' ').' km '.($n['km_left'] < 0 ? 'over' : 'to go') : '' }}</span>
                                    @else<span class="fl-dash">Not recorded</span>@endif
                                </td>
                                <td data-label="Last">{!! $n['last'] ? e($n['last']->performed_on->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y')).'<span class="fl-sub">'.e(\App\Domain\Fleet\Models\VehicleMaintenanceRecord::KINDS[$n['last']->kind] ?? $n['last']->kind).'</span>' : '<span class="fl-dash">&#8212;</span>' !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="fl-panel" aria-labelledby="log-title">
        <div class="fl-panel__head"><h2 id="log-title">Log</h2><span class="fl-panel__meta">Latest 25 records. Record a service on the vehicle's page.</span></div>
        @if ($log->isEmpty())
            <p class="fl-note">Nothing recorded yet.</p>
        @else
            <ul class="fl-items">
                @foreach ($log as $record)
                    <li style="grid-template-columns: 6.5rem auto minmax(0,1fr) auto">
                        <p class="fl-items__who" style="margin:0">{{ $record->performed_on->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y') }}</p>
                        <span class="fl-plate">{{ $record->vehicle?->plate_number }}</span>
                        <div><p class="fl-items__title">{{ $kinds[$record->kind] ?? ucfirst($record->kind) }}</p><p class="fl-items__detail">{{ $record->description }}</p></div>
                        <p class="fl-items__who" style="text-align:right">{{ $record->odometer_km !== null ? number_format($record->odometer_km, 0, '.', ' ').' km' : '' }}@if ($record->cost_minor !== null)<br><x-eh.money :minor="$record->cost_minor" :currency="$record->currency" />@endif</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-fleet.page>
