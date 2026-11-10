@php
    use App\Domain\Ops\Insights\ColumnChart;
    use App\Domain\Ops\Support\Durations;
    use App\Support\Ui\MoneyFormatter;
    $pct = fn (?int $bp) => $bp === null ? '-' : rtrim(rtrim(number_format($bp / 100, 1, '.', ''), '0'), '.').'%';
    $count = fn (int $v) => number_format($v);
    $money = fn (int $minor) => MoneyFormatter::format($minor, 'ZAR');
    $short = fn (int $minor) => $minor >= 100000 ? 'R'.number_format($minor / 100000, 0).'k' : MoneyFormatter::format($minor, 'ZAR', false, true);
    $volume = $data['ride_volume'];
    $completed = $data['completed_trips'];
    $cancel = $data['cancellations'];
    $eta = $data['pickup_eta'];
    $dur = $data['trip_duration'];
    $util = $data['utilisation'];
    $rev = $data['revenue'];
    $cov = $data['coverage'];
    $inc = $data['incidents'];
    $volumeChart = ColumnChart::build(array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['value'], 'display' => $count($r['value']).' '.\Illuminate\Support\Str::plural('ride', $r['value'])], $volume['rows']), $count);
    $completedChart = ColumnChart::build(array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['value'], 'display' => $count($r['value']).' completed'], $completed['rows']), $count, 720, 160);
    $revChart = ColumnChart::build(array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['commission'] + $r['fees'], 'display' => $money($r['commission'] + $r['fees'])], $rev['rows']), $short, 720, 160);
    $incChart = ColumnChart::build(array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['value'], 'display' => $r['value'].' '.\Illuminate\Support\Str::plural('incident', $r['value'])], $inc['rows']), $count, 720, 140);
    $cancelTotal = array_sum(array_column($cancel['rows'], 'count'));
    $completionRate = $cancel['total'] > 0 ? intdiv($cancel['completed'] * 10000 + intdiv($cancel['total'], 2), $cancel['total']) : null;
    $finding = $volume['total'] === 0
        ? 'No ride was requested in this period.'
        : $count($volume['total']).' '.\Illuminate\Support\Str::plural('ride', $volume['total']).' requested; '.$pct($completionRate).' of them completed, '.$pct($cancel['total'] > 0 ? intdiv($cancelTotal * 10000 + intdiv($cancel['total'], 2), $cancel['total']) : null).' cancelled, expired or failed.';
    $def = fn (string $key) => $definitions[$key];
@endphp
<x-ops-layout title="Insights">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Insights</h1>
                <p class="ops-head__finding">{{ $finding }}</p>
            </div>
            <div class="ops-head__actions">
                <span class="ops-stamp">{{ $from->format('d M Y') }} to {{ $to->format('d M Y') }} · read {{ \Illuminate\Support\Carbon::parse($data['generated_at'])->format('H:i:s') }} (kept 60 s)</span>
                <x-eh.button size="sm" variant="secondary" :href="route('ops.insights.export', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'grain' => $grain])" icon="download">CSV</x-eh.button>
            </div>
        </header>

        <form class="ops-filter" method="GET" action="{{ route('ops.insights') }}" aria-label="Date range">
            <x-eh.field label="From" name="from" type="date" :value="$from->toDateString()" />
            <x-eh.field label="To" name="to" type="date" :value="$to->toDateString()" />
            <x-eh.select label="Group by" name="grain" :options="['day' => 'Day', 'week' => 'Week', 'month' => 'Month']" :value="$grain" />
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
            <span class="ops-act__row" style="margin-left:auto">
                @foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $days => $label)
                    <x-eh.button size="sm" variant="ghost" :href="route('ops.insights', ['from' => now()->subDays($days - 1)->toDateString(), 'to' => now()->toDateString(), 'grain' => $days > 62 ? 'week' : 'day'])">{{ $label }}</x-eh.button>
                @endforeach
            </span>
        </form>
        <p class="ops-note ops-note--small" style="margin:calc(-1 * var(--eh-space-2)) 0 var(--eh-space-4)">Days are UTC dates as stored (South African time is UTC+2): a ride at 01:00 SAST counts on the previous day. Every figure is counted from recorded rows; nothing is estimated.</p>

        <dl class="ops-figures" aria-label="Period figures">
            <div><dt title="{{ $def('ride_volume') }}">Rides requested</dt><dd>{{ $count($volume['total']) }}</dd></div>
            <div><dt title="{{ $def('completed_trips') }}">Trips completed</dt><dd>{{ $count($completed['total']) }}</dd></div>
            <div><dt title="{{ $def('trip_duration') }}">Average trip</dt><dd @if($dur['average_s'] === null) data-empty @endif>{{ $dur['average_s'] === null ? '-' : Durations::short($dur['average_s']) }}</dd></div>
            <div><dt title="{{ $def('utilisation') }}">Driver utilisation</dt><dd @if($util['rate_bp'] === null) data-empty @endif>{{ $pct($util['rate_bp']) }}</dd></div>
            <div><dt title="{{ $def('revenue') }}">Platform revenue</dt><dd>{{ $money($rev['totals']['commission'] + $rev['totals']['fees']) }}</dd></div>
            <div data-tone="{{ $inc['total'] > 0 ? 'caution' : '' }}"><dt title="{{ $def('incidents') }}">Safety incidents</dt><dd>{{ $count($inc['total']) }}</dd></div>
        </dl>

        <div class="ops-split">
            <section class="ops-panel ops-metric" aria-labelledby="vol-h">
                <div class="ops-panel__head" style="margin:0"><h2 id="vol-h">Ride volume</h2><span class="ops-panel__meta">rides per {{ $grain }}</span></div>
                <p class="ops-metric__def">Defined as <code>{{ $def('ride_volume') }}</code>.</p>
                @include('ops.partials.columns', ['chart' => $volumeChart, 'title' => 'Rides requested per '.$grain, 'empty' => 'No ride was requested in this period.'])
                <h3 style="font-size:var(--eh-text-sm);margin:var(--eh-space-2) 0 0">Completed trips</h3>
                <p class="ops-metric__def">Defined as <code>{{ $def('completed_trips') }}</code>.</p>
                @include('ops.partials.columns', ['chart' => $completedChart, 'title' => 'Trips completed per '.$grain, 'empty' => 'No completed trips in this period.'])
                <details>
                    <summary class="ops-note ops-note--small" style="cursor:pointer">Table of these figures</summary>
                    <div class="ops-tablewrap"><table class="ops-table ops-table--compact">
                        <thead><tr><th>Period</th><th class="num">Requested</th><th class="num">Completed</th><th class="num">Average trip</th></tr></thead>
                        <tbody>@foreach ($volume['rows'] as $i => $r)<tr><td>{{ $r['label'] }}</td><td class="num">{{ $count($r['value']) }}</td><td class="num">{{ $count($completed['rows'][$i]['value']) }}</td><td class="num">{{ $dur['rows'][$i]['average_s'] === null ? '-' : Durations::short($dur['rows'][$i]['average_s']) }}</td></tr>@endforeach</tbody>
                    </table></div>
                </details>
            </section>

            <div class="ops-stack">
                <section class="ops-panel ops-metric" aria-labelledby="can-h">
                    <div class="ops-panel__head" style="margin:0"><h2 id="can-h">How rides ended</h2><span class="ops-panel__meta">of {{ $count($cancel['total']) }} requested</span></div>
                    <p class="ops-metric__def">Defined as <code>{{ $def('cancellations') }}</code>.</p>
                    @if ($cancel['total'] === 0)
                        <p class="ops-none">No ride was requested in this period.</p>
                    @else
                        @php($maxBp = max(1, max(array_map(fn ($r) => (int) $r['rate_bp'], $cancel['rows']))))
                        <table class="ops-table ops-table--compact" style="margin:0 calc(-1 * var(--eh-space-5));width:calc(100% + 2 * var(--eh-space-5))">
                            <tbody>
                                <tr><td>Completed</td><td class="num">{{ $count($cancel['completed']) }}</td><td class="num">{{ $pct($completionRate) }}</td></tr>
                                @foreach ($cancel['rows'] as $r)
                                    <tr><td><span class="ops-bar-inline" style="width:{{ round(64 * (int) $r['rate_bp'] / $maxBp) }}px" aria-hidden="true"></span>{{ $r['label'] }}</td><td class="num">{{ $count($r['count']) }}</td><td class="num">{{ $pct($r['rate_bp']) }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                        <p class="ops-note ops-note--small">The rest are still live or scheduled.</p>
                    @endif
                </section>

                <section class="ops-panel ops-metric" aria-labelledby="eta-h">
                    <div class="ops-panel__head" style="margin:0"><h2 id="eta-h">Pickup: estimated against actual</h2><span class="ops-panel__meta">{{ $count($eta['count']) }} {{ \Illuminate\Support\Str::plural('pickup', $eta['count']) }}</span></div>
                    <p class="ops-metric__def">Defined as <code>{{ $def('pickup_eta') }}</code>.</p>
                    @if ($eta['count'] === 0)
                        <p class="ops-none">No driver reached a pickup in this period.</p>
                    @else
                        <dl class="ops-facts">
                            <dt>Estimated, average</dt><dd class="num">{{ Durations::short($eta['estimated_s']) }}</dd>
                            <dt>Actual, average</dt><dd class="num">{{ Durations::short($eta['actual_s']) }}</dd>
                            <dt>Later than estimated</dt><dd class="num">{{ $count($eta['late']) }} of {{ $count($eta['count']) }}</dd>
                        </dl>
                        <table class="ops-table ops-table--compact" style="margin-top:var(--eh-space-3)">
                            <thead><tr><th>ETA method</th><th class="num">Pickups</th><th class="num">Estimated</th><th class="num">Actual</th></tr></thead>
                            <tbody>@foreach ($eta['by_method'] as $m)<tr><td>{{ str_replace('_', ' ', $m['method'] ?: 'not recorded') }}</td><td class="num">{{ $m['count'] }}</td><td class="num">{{ Durations::short($m['estimated_s']) }}</td><td class="num">{{ Durations::short($m['actual_s']) }}</td></tr>@endforeach</tbody>
                        </table>
                        @if (collect($eta['by_method'])->contains('method', 'straight_line_estimate'))<p class="ops-note ops-note--small">Straight-line estimates are used for ranking drivers only; they are expected to undershoot real road time.</p>@endif
                    @endif
                </section>

                <section class="ops-panel ops-metric" aria-labelledby="util-h">
                    <div class="ops-panel__head" style="margin:0"><h2 id="util-h">Driver utilisation</h2><span class="ops-panel__meta">{{ $util['drivers'] }} {{ \Illuminate\Support\Str::plural('driver', $util['drivers']) }} measured</span></div>
                    <p class="ops-metric__def">Defined as <code>{{ $def('utilisation') }}</code>.</p>
                    @if ($util['measured_since'] === null)
                        <p class="ops-none">Not measured yet: online time is recorded from the moment drivers go online with this release.</p>
                    @elseif ($util['online_s'] === 0)
                        <p class="ops-none">No driver was online in this period.</p>
                    @else
                        <dl class="ops-facts">
                            <dt>With a ride</dt><dd class="num">{{ Durations::short($util['on_trip_s']) }}</dd>
                            <dt>Online in all</dt><dd class="num">{{ Durations::short($util['online_s']) }}</dd>
                            <dt>Share with a ride</dt><dd class="num"><strong>{{ $pct($util['rate_bp']) }}</strong></dd>
                        </dl>
                    @endif
                    @if ($util['measured_since'])<p class="ops-note ops-note--small">Measured since {{ \Illuminate\Support\Carbon::parse($util['measured_since'])->format('d M Y H:i') }}; time before that is not counted.</p>@endif
                </section>
            </div>
        </div>

        <div class="ops-split" style="margin-top:var(--ops-gap)">
            <section class="ops-panel ops-metric" aria-labelledby="rev-h">
                <div class="ops-panel__head" style="margin:0"><h2 id="rev-h">Platform revenue and driver earnings</h2><span class="ops-panel__meta">from the ledger</span></div>
                <p class="ops-metric__def">Defined as <code>{{ $def('revenue') }}</code>.</p>
                @include('ops.partials.columns', ['chart' => $revChart, 'title' => 'Platform revenue per '.$grain, 'empty' => 'No revenue was posted in this period.'])
                <dl class="ops-facts">
                    <dt>Commission</dt><dd class="num">{{ $money($rev['totals']['commission']) }}</dd>
                    <dt>Booking and service fees</dt><dd class="num">{{ $money($rev['totals']['fees']) }}</dd>
                    <dt>Driver earnings</dt><dd class="num">{{ $money($rev['totals']['driver_earnings']) }}</dd>
                    <dt>Refunds</dt><dd class="num">{{ $money($rev['totals']['refunds']) }}</dd>
                    <dt>Promotions funded</dt><dd class="num">{{ $money($rev['totals']['promotions']) }}</dd>
                </dl>
            </section>

            <div class="ops-stack">
                <section class="ops-panel ops-metric" aria-labelledby="inc-h">
                    <div class="ops-panel__head" style="margin:0"><h2 id="inc-h">Safety incidents</h2><span class="ops-panel__meta">{{ $inc['total'] }} opened</span></div>
                    <p class="ops-metric__def">Defined as <code>{{ $def('incidents') }}</code>.</p>
                    @include('ops.partials.columns', ['chart' => $incChart, 'title' => 'Safety incidents opened per '.$grain, 'empty' => 'No safety incident was opened in this period.'])
                    @if ($inc['total'] > 0)
                        <p class="ops-note ops-note--small">Critical {{ array_sum(array_column($inc['rows'], 'critical')) }}, high {{ array_sum(array_column($inc['rows'], 'high')) }}, medium {{ array_sum(array_column($inc['rows'], 'medium')) }}, low {{ array_sum(array_column($inc['rows'], 'low')) }}.</p>
                    @endif
                </section>
                <section class="ops-panel ops-metric" aria-labelledby="cov-h">
                    <div class="ops-panel__head" style="margin:0"><h2 id="cov-h">Service coverage</h2></div>
                    <p class="ops-metric__def">Defined as <code>{{ $def('coverage') }}</code>.</p>
                    <table class="ops-table ops-table--compact" style="margin:0 calc(-1 * var(--eh-space-5));width:calc(100% + 2 * var(--eh-space-5))">
                        <thead><tr><th>Area</th><th class="num">Requests</th><th class="num">No driver found</th></tr></thead>
                        <tbody>
                            @forelse ($cov['rows'] as $r)
                                <tr><td>{{ $r['area'] }}</td><td class="num">{{ $count($r['requests']) }}</td><td class="num">{{ $pct($r['rate_bp']) }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="muted">No active service area.</td></tr>
                            @endforelse
                            <tr><td>Outside every active area</td><td class="num">{{ $count($cov['outside']['requests']) }}</td><td class="num">{{ $cov['outside']['requests'] > 0 ? $pct(intdiv($cov['outside']['expired'] * 10000, $cov['outside']['requests'])) : '-' }}</td></tr>
                        </tbody>
                    </table>
                    <p class="ops-note ops-note--small">Requests refused before a ride exists (a quote outside every service area) are not recorded anywhere, so they cannot be counted here. {{ $cov['no_coordinates'] > 0 ? $cov['no_coordinates'].' ride(s) had no pickup coordinates (address-only bookings).' : '' }}</p>
                </section>
            </div>
        </div>

        <section class="ops-panel ops-metric" aria-labelledby="ret-h" style="margin-top:var(--ops-gap)">
            <div class="ops-panel__head" style="margin:0"><h2 id="ret-h">Passenger retention</h2><span class="ops-panel__meta">{{ $count($data['retention']['passengers']) }} passengers with a completed ride, cohorts by first ride month</span></div>
            <p class="ops-metric__def">Defined as <code>{{ $def('retention') }}</code>. A darker cell is a larger share; a blank cell is a month that has not happened yet.</p>
            <div class="ops-tablewrap"><table class="ops-table ops-table--compact ops-cohort">
                <thead><tr><th>First ride</th><th class="num">Passengers</th>@for ($k = 1; $k < count($data['retention']['rows']); $k++)<th class="num">Month {{ $k }}</th>@endfor</tr></thead>
                <tbody>
                    @foreach ($data['retention']['rows'] as $row)
                        <tr>
                            <td>{{ $row['label'] }}</td>
                            <td class="num">{{ $row['size'] }}</td>
                            @foreach ($row['cells'] as $cell)
                                @if ($cell === null)<td class="cell"></td>
                                @elseif ($row['size'] === 0)<td class="cell muted">-</td>
                                @else<td class="cell" style="background:color-mix(in srgb, var(--ops-chart) {{ min(85, (int) round(($cell['rate_bp'] ?? 0) / 100 * 0.85)) }}%, transparent);{{ ($cell['rate_bp'] ?? 0) > 5000 ? 'color:var(--eh-accent-ink)' : '' }}" title="{{ $cell['count'] }} of {{ $row['size'] }}">{{ $pct($cell['rate_bp']) }}</td>@endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    </div>
</x-ops-layout>
