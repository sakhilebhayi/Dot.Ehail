@php
    $t = $statement['totals'];
    $cur = $statement['currency'];
    $periodLabel = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'][$period];
    $range = $statement['from']->format('j M').($period === 'day' ? '' : ' to '.$statement['to']->format('j M Y'));
    $anchor = $date ? \Illuminate\Support\Carbon::parse($date) : now();
    $step = ['day' => 'addDay', 'week' => 'addWeek', 'month' => 'addMonth'][$period];
    $back = ['day' => 'subDay', 'week' => 'subWeek', 'month' => 'subMonth'][$period];
    $rec = $reconciliation;
    $allMatch = $rec['fleet']['matches'] && collect($rec['drivers'])->every(fn ($d) => $d['matches'] !== false);
    $percent = fn (int $bp) => rtrim(rtrim(number_format($bp / 100, 2), '0'), '.').'%';
@endphp
<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Earnings"
    :line="$t['trips'] === 0 && $t['net_minor'] === 0 ? 'No earnings recorded for '.$range.'.' : 'Drivers earned '.\App\Support\Ui\MoneyFormatter::format($t['net_minor'], $cur).' after commission from '.$t['trips'].' '.\Illuminate\Support\Str::plural('trip', $t['trips']).', '.$range.'.'">
    <x-slot:actions>
        <x-eh.button :href="route('fleet.earnings.csv', array_filter(['period' => $period, 'date' => $date]))" variant="secondary" size="sm" icon="download">CSV</x-eh.button>
    </x-slot:actions>

    <div class="fl-filters">
        <nav class="fl-seg" aria-label="Period">
            @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $key => $label)
                <a href="{{ route('fleet.earnings', ['period' => $key]) }}" @if($period === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <nav class="fl-inline" aria-label="Move through periods">
            <x-eh.button :href="route('fleet.earnings', ['period' => $period, 'date' => $anchor->copy()->{$back}()->toDateString()])" variant="ghost" size="sm" icon="arrow-left">Earlier</x-eh.button>
            <span class="fl-panel__meta">{{ $range }}</span>
            @if ($statement['to']->lt(now()))
                <x-eh.button :href="route('fleet.earnings', ['period' => $period, 'date' => $anchor->copy()->{$step}()->toDateString()])" variant="ghost" size="sm" icon-right="arrow-right">Later</x-eh.button>
            @endif
        </nav>
    </div>

    <dl class="fl-strip" style="--fl-cells: 5" aria-label="Totals for {{ $range }}">
        <div class="fl-strip__cell"><dt>Fares</dt><dd><x-eh.money :minor="$t['fare_minor']" :currency="$cur" /></dd><dd class="fl-strip__note">{{ $t['trips'] }} {{ \Illuminate\Support\Str::plural('trip', $t['trips']) }}</dd></div>
        <div class="fl-strip__cell"><dt>Commission</dt><dd><x-eh.money :minor="$t['commission_minor']" :currency="$cur" /></dd><dd class="fl-strip__note">kept by the platform</dd></div>
        <div class="fl-strip__cell"><dt>Drivers' net</dt><dd><x-eh.money :minor="$t['net_minor']" :currency="$cur" /></dd><dd class="fl-strip__note">incl. incentives <x-eh.money :minor="$t['incentives_minor']" :currency="$cur" /></dd></div>
        <div class="fl-strip__cell"><dt>Cash collected</dt><dd><x-eh.money :minor="$t['cash_collected_minor']" :currency="$cur" /></dd><dd class="fl-strip__note">held by drivers</dd></div>
        <div class="fl-strip__cell"><dt>Not paid out yet</dt><dd><x-eh.money :minor="$t['unpaid_minor']" :currency="$cur" /></dd><dd class="fl-strip__note"><x-eh.money :minor="$t['paid_minor']" :currency="$cur" /> paid</dd></div>
    </dl>

    <div class="fl-grid fl-grid--even">
        <section class="fl-panel fl-panel--flush" aria-labelledby="bydriver-title">
            <div class="fl-panel__head"><h2 id="bydriver-title">By driver</h2></div>
            @if ($statement['by_driver'] === [])
                <p class="fl-note" style="padding: 0 var(--eh-space-5) var(--eh-space-4)">No earnings in this period.</p>
            @else
                <table class="fl-table fl-table--stack">
                    <thead><tr><th scope="col">Driver</th><th scope="col" class="num">Trips</th><th scope="col" class="num">Fares</th><th scope="col" class="num">Commission</th><th scope="col" class="num">Net</th></tr></thead>
                    <tbody>
                        @foreach ($statement['by_driver'] as $row)
                            <tr><td>{{ $row['name'] }}</td><td class="num" data-label="Trips">{{ $row['trips'] }}</td><td class="num" data-label="Fares"><x-eh.money :minor="$row['fare_minor']" :currency="$cur" /></td><td class="num" data-label="Commission"><x-eh.money :minor="$row['commission_minor']" :currency="$cur" /></td><td class="num" data-label="Net"><strong><x-eh.money :minor="$row['net_minor']" :currency="$cur" /></strong></td></tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><td>Total</td><td class="num" data-label="Trips">{{ $t['trips'] }}</td><td class="num" data-label="Fares"><x-eh.money :minor="$t['fare_minor']" :currency="$cur" /></td><td class="num" data-label="Commission"><x-eh.money :minor="$t['commission_minor']" :currency="$cur" /></td><td class="num" data-label="Net"><x-eh.money :minor="$t['net_minor']" :currency="$cur" /></td></tr></tfoot>
                </table>
            @endif
        </section>

        <section class="fl-panel fl-panel--flush" aria-labelledby="byvehicle-title">
            <div class="fl-panel__head"><h2 id="byvehicle-title">By vehicle</h2><span class="fl-panel__meta">Trips only</span></div>
            @if ($statement['by_vehicle'] === [])
                <p class="fl-note" style="padding: 0 var(--eh-space-5) var(--eh-space-4)">No trips in this period.</p>
            @else
                <table class="fl-table fl-table--stack">
                    <thead><tr><th scope="col">Vehicle</th><th scope="col" class="num">Trips</th><th scope="col" class="num">Fares</th><th scope="col" class="num">Net</th></tr></thead>
                    <tbody>
                        @foreach ($statement['by_vehicle'] as $row)
                            <tr><td>@if ($row['vehicle_id'])<span class="fl-plate">{{ $row['plate'] }}</span>@else<span class="fl-dash">{{ $row['plate'] }}</span>@endif</td><td class="num" data-label="Trips">{{ $row['trips'] }}</td><td class="num" data-label="Fares"><x-eh.money :minor="$row['fare_minor']" :currency="$cur" /></td><td class="num" data-label="Net"><x-eh.money :minor="$row['net_minor']" :currency="$cur" /></td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    <div class="fl-grid fl-grid--even">
        <section class="fl-panel" aria-labelledby="arr-title">
            <div class="fl-panel__head"><h2 id="arr-title">Arrangement</h2></div>
            <dl class="fl-kv">
                <dt>Paid to</dt>
                <dd>{{ $payeeMode === 'fleet_owner' && $fleet->isApproved() ? 'The fleet owner is paid the drivers\' earnings and pays the drivers.' : 'Each driver is paid their own earnings directly.' }}{{ $fleet->payee_mode === null ? ' (platform setting)' : '' }}</dd>
                <dt>Commission</dt>
                <dd>
                    @php($current = $fleetPlans->firstWhere('retired_at', null))
                    @if ($current)
                        Fleet plan "{{ $current->name }}" v{{ $current->version }}: {{ $percent($current->percent_bp) }}{{ $current->fixed_minor ? ' plus '.\App\Support\Ui\MoneyFormatter::format($current->fixed_minor, $current->currency) : '' }} of the trip fare{{ $current->min_commission_minor !== null ? ', at least '.\App\Support\Ui\MoneyFormatter::format($current->min_commission_minor, $current->currency) : '' }}{{ $current->max_commission_minor !== null ? ', at most '.\App\Support\Ui\MoneyFormatter::format($current->max_commission_minor, $current->currency) : '' }}.
                    @elseif ($marketPlans->isNotEmpty())
                        No plan for this fleet; the market plan applies: {{ $marketPlans->map(fn ($p) => $p->name.' '.$percent($p->percent_bp))->implode('; ') }}.
                    @else
                        No commission plan is published; the platform's configured rate applies and each earning names it.
                    @endif
                </dd>
            </dl>
            <p class="fl-note" style="margin-top: var(--eh-space-3)">Commission plans are set by operations per market, category or fleet. A split between the fleet and its drivers outside Dot.Ehail is not recorded here.</p>
        </section>

        <section class="fl-panel" aria-labelledby="ledger-title">
            <div class="fl-panel__head">
                <h2 id="ledger-title">Ledger check</h2>
                <x-eh.badge :tone="$allMatch ? 'go' : 'stop'" :icon="$allMatch ? 'check' : 'alert'">{{ $allMatch ? 'Matches' : 'Does not match' }}</x-eh.badge>
            </div>
            <table class="fl-table fl-table--stack" style="margin-inline: calc(-1 * var(--eh-space-5)); width: calc(100% + 2 * var(--eh-space-5))">
                <thead><tr><th scope="col">Owed to</th><th scope="col" class="num">Ledger</th><th scope="col" class="num">Unpaid earnings</th></tr></thead>
                <tbody>
                    <tr><td>Fleet owner</td><td class="num" data-label="Ledger"><x-eh.money :minor="$rec['fleet']['ledger_minor']" :currency="$rec['currency']" /></td><td class="num" data-label="Earnings"><x-eh.money :minor="$rec['fleet']['earnings_minor']" :currency="$rec['currency']" /></td></tr>
                    @foreach ($rec['drivers'] as $d)
                        <tr>
                            <td>{{ $d['name'] }}@if ($d['note'])<span class="fl-sub">{{ $d['note'] }}</span>@endif</td>
                            <td class="num" data-label="Ledger">{!! $d['ledger_minor'] === null ? '<span class="fl-dash">&#8212;</span>' : '' !!}@if ($d['ledger_minor'] !== null)<x-eh.money :minor="$d['ledger_minor']" :currency="$rec['currency']" />@endif</td>
                            <td class="num" data-label="Earnings"><x-eh.money :minor="$d['earnings_minor']" :currency="$rec['currency']" />@if ($d['matches'] === false)<span class="fl-sub fl-sev--stop">Differs</span>@endif</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="fl-note" style="margin-top: var(--eh-space-3)">What the platform's double-entry ledger says it owes, next to the sum of this fleet's earnings not yet paid out. A difference is a finance issue for operations.</p>
        </section>
    </div>
</x-fleet.page>
