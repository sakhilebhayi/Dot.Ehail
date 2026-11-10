@php
    $canWork = $yard->whereIn('state.key', ['working', 'ready'])->count();
    $blocked = $yard->whereIn('state.key', ['blocked', 'out', 'unassigned']);
    $line = $yard->isEmpty()
        ? ($fleet->isIndependent() ? 'Add your vehicle to see whether it can go online, what is due and what you earned.' : 'No vehicles yet. Add the first one, then give it to a driver.')
        : $canWork.' of '.$yard->count().' '.\Illuminate\Support\Str::plural('vehicle', $yard->count()).' can work now.'
            .($blocked->isNotEmpty() ? ' '.$blocked->count().' cannot: '.$blocked->take(2)->map(fn ($r) => $r['vehicle']->plate_number.' ('.\Illuminate\Support\Str::lower(rtrim($r['state']['note'], '.')).')')->implode(', ').($blocked->count() > 2 ? ' and more' : '').'.' : '');
    $toneIcon = ['stop' => 'alert', 'caution' => 'warning', 'info' => 'info'];
    $areaRoute = ['drivers' => 'fleet.drivers.show', 'vehicles' => 'fleet.vehicles.show', 'maintenance' => 'fleet.vehicles.show'];
@endphp
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Overview" :heading="$fleet->name" :line="$line">
    <x-slot:actions>
        @if ($access->can(auth()->user(), $fleet, 'vehicles', true))
            <x-eh.button :href="route('fleet.vehicles.create')" icon="plus">Add vehicle</x-eh.button>
        @endif
    </x-slot:actions>

    @unless ($fleet->isApproved())
        <x-eh.banner tone="caution" title="In review: operations check every new fleet">
            Our operations team checks every new fleet. Until it is approved, driver applications are decided by operations, and other drivers' records stay hidden from the fleet. You can add vehicles and fill in the fleet details now.
            @if (Route::has('fleets.show')) <a class="eh-link" href="{{ route('fleets.show', $fleet) }}">See the applications on your fleet page</a>.@endif
        </x-eh.banner>
    @endunless

    <dl class="fl-strip" style="--fl-cells: {{ $week ? 5 : 4 }}" aria-label="This fleet now">
        <div class="fl-strip__cell"><dt>Drivers</dt><dd>{{ $drivers->where('status', 'approved')->count() }}</dd><dd class="fl-strip__note">{{ $drivers->where('status', 'suspended')->count() }} suspended, {{ $applicationsCount ?? $applications->count() }} applying</dd></div>
        <div class="fl-strip__cell"><dt>Vehicles</dt><dd>{{ $yard->count() }}</dd><dd class="fl-strip__note">{{ $yard->where('vehicle.owned_by', 'fleet')->count() }} held by the fleet</dd></div>
        <div class="fl-strip__cell"><dt>Online now</dt><dd>{{ $onlineNow }}</dd><dd class="fl-strip__note">online, paused or on a trip</dd></div>
        <div class="fl-strip__cell"><dt>Trips this week</dt><dd>{{ $tripsThisWeek }}</dd><dd class="fl-strip__note">completed since Monday</dd></div>
        @if ($week)
            <div class="fl-strip__cell"><dt>Earned this week</dt><dd><a href="{{ route('fleet.earnings') }}"><x-eh.money :minor="$week['totals']['net_minor']" :currency="$week['currency']" /></a></dd><dd class="fl-strip__note">drivers' net after commission</dd></div>
        @endif
    </dl>

    <div class="fl-grid">
        <div class="fl-stack">
        <section class="fl-panel fl-panel--flush" aria-labelledby="yard-title">
            <div class="fl-panel__head">
                <h2 id="yard-title">Vehicles today</h2>
                <a class="eh-link eh-tap fl-panel__meta" href="{{ route('fleet.vehicles') }}">All vehicles</a>
            </div>
            @if ($yard->isEmpty())
                <div style="padding: 0 var(--eh-space-5) var(--eh-space-4)">
                    <x-eh.empty title="No vehicles in this fleet yet" :heading-level="3">
                        <p style="margin:0">A vehicle the fleet holds can be given to any driver you allow; a driver's own car appears here when they join.</p>
                    </x-eh.empty>
                </div>
            @else
                <div class="fl-table-wrap">
                    <table class="fl-table fl-table--stack">
                        <thead><tr><th scope="col">Plate</th><th scope="col">Vehicle</th><th scope="col">Driver</th><th scope="col">Now</th><th scope="col">Next service</th></tr></thead>
                        <tbody>
                            @foreach ($yard as $row)
                                @php($v = $row['vehicle'])
                                <tr>
                                    <td><a class="fl-plate" href="{{ route('fleet.vehicles.show', $v) }}">{{ $v->plate_number }}</a></td>
                                    <td class="num" style="text-align:left">{{ $v->make }} {{ $v->model }}<span class="fl-sub">{{ $v->type === 'suv' ? 'SUV' : ucfirst($v->type) }}, {{ $v->owned_by === 'fleet' ? 'fleet vehicle' : "driver's own" }}</span></td>
                                    <td data-label="Driver">{!! $row['holder'] ? e($row['holder']) : '<span class="fl-dash">Nobody</span>' !!}</td>
                                    <td class="fl-span"><x-eh.badge :tone="$row['state']['tone']">{{ $row['state']['label'] }}</x-eh.badge><span class="fl-sub">{{ $row['state']['note'] }}</span></td>
                                    <td data-label="Service">
                                        @if ($row['service']['on'] || $row['service']['km'])
                                            <span class="fl-nowrap {{ $row['service']['overdue'] ? 'fl-sev--stop' : ($row['service']['due_soon'] ? 'fl-sev--caution' : '') }}">{{ $row['service']['on']?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('j M Y') }}{{ $row['service']['on'] && $row['service']['km'] ? ', ' : '' }}{{ $row['service']['km'] ? number_format($row['service']['km'], 0, '.', ' ').' km' : '' }}</span>
                                            @if ($row['service']['overdue'])<span class="fl-sub">Overdue</span>@elseif ($row['service']['due_soon'])<span class="fl-sub">Due soon</span>@endif
                                        @else
                                            <span class="fl-dash">Not recorded</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
            @if ($applications->isNotEmpty() || ! $fleet->isIndependent())
                <section aria-labelledby="apps-title">
                    <h2 class="fl-rail-title" id="apps-title">Applications waiting</h2>
                    <div class="fl-panel">
                        @if ($applications->isEmpty())
                            <p class="fl-note">No driver is waiting for a decision. Drivers apply from the driver app by choosing this fleet once it is approved.</p>
                        @else
                            <ul class="fl-items">
                                @foreach ($applications as $application)
                                    <li style="grid-template-columns: auto minmax(0,1fr)">
                                        <x-eh.avatar :name="$application->user?->name ?? 'Driver'" :size="32" />
                                        <div>
                                            <p class="fl-items__title">{{ $application->user?->name }}</p>
                                            <p class="fl-items__who">Applied {{ $application->created_at->diffForHumans() }}</p>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="fl-panel__foot"><x-eh.button :href="route('fleet.drivers')" variant="secondary" size="sm">Review applications</x-eh.button></div>
                        @endif
                    </div>
                </section>
            @endif
        </div>

        <div class="fl-stack">
            <section aria-labelledby="alerts-title">
                <h2 class="fl-rail-title" id="alerts-title">Needs attention</h2>
                <div class="fl-panel">
                    @if ($alerts === [])
                        <p class="fl-note">Nothing needs attention: no document is missing or expiring within {{ config('ehail_fleet.alerts.document_expiry_days') }} days, no service is due and no vehicle is off the road.</p>
                    @else
                        <ul class="fl-items">
                            @foreach ($alerts as $alert)
                                <li>
                                    <span class="fl-sev--{{ $alert['severity'] }}"><x-eh.icon :name="$toneIcon[$alert['severity']]" :label="['stop' => 'Blocking', 'caution' => 'Soon', 'info' => 'Note'][$alert['severity']]" /></span>
                                    <div>
                                        <p class="fl-items__title">{{ $alert['title'] }}</p>
                                        <p class="fl-items__who">
                                            @if ($alert['link'] && isset($areaRoute[$alert['link']['area']]))
                                                <a class="eh-link eh-tap" href="{{ route($areaRoute[$alert['link']['area']], $alert['link']['id']) }}">{{ $alert['subject'] }}</a>
                                            @else
                                                {{ $alert['subject'] }}
                                            @endif
                                        </p>
                                        <p class="fl-items__detail">{{ $alert['detail'] }}</p>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <div class="fl-panel__foot"><x-eh.button :href="route('fleet.alerts')" variant="secondary" size="sm">All alerts ({{ $alertCount }})</x-eh.button></div>
                    @endif
                </div>
            </section>

        </div>
    </div>
</x-fleet.page>
