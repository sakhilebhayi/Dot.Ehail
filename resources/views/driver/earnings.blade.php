{{-- Earnings statement for a day, week or month: every figure from the money domain, in integer minor units. --}}
@php
    $t = $statement['totals'];
    $cur = $statement['currency'];
    $label = match ($period) {
        'day' => $from->isToday() ? 'Today' : \App\Http\Controllers\Driver\Support\Local::at($from)->isoFormat('dddd D MMMM'),
        'week' => 'Week of '.\App\Http\Controllers\Driver\Support\Local::at($from)->isoFormat('D MMMM'),
        'month' => \App\Http\Controllers\Driver\Support\Local::at($from)->isoFormat('MMMM YYYY'),
    };
    $tabs = collect(['day' => 'Day', 'week' => 'Week', 'month' => 'Month'])->map(fn ($l, $p) => ['label' => $l, 'href' => route('drive.earnings', ['period' => $p])])->all();
    $kinds = ['trip' => 'Trip', 'cancellation_fee' => 'Cancellation fee', 'incentive' => 'Incentive', 'adjustment' => 'Adjustment'];
    $statusWord = ['pending' => 'Pending', 'available' => 'Available', 'paid' => 'Paid'];
    $statusTone = ['pending' => 'neutral', 'available' => 'go', 'paid' => 'info'];
    // The ledger must add up on screen: what is neither commission nor what you keep (platform fees and tax inside
    // the fare, other deductions) is the difference of the shown figures, never a separate guess.
    $tripNet = $t['net_minor'] - $t['incentives_minor'] - $t['cancellation_fees_minor'] - $t['adjustments_minor'];
    $other = $t['fare_minor'] - $t['commission_minor'] - $tripNet;
    $hours = $onlineSeconds !== null ? intdiv($onlineSeconds, 3600) : null;
    $mins = $onlineSeconds !== null ? intdiv($onlineSeconds % 3600, 60) : null;
@endphp
<x-driver-layout title="Earnings">
    <x-slot:head>
        @vite(['resources/css/driver.css'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>Earnings</h1>
            <x-eh.tabs :items="$tabs" :active="$period" label="Statement period" />
        </div>
    </x-slot:header>

    <div class="dr-page">
        <nav class="dr-periodnav" aria-label="Choose the period">
            <x-eh.icon-button icon="chevron-left" :label="'Previous '.$period" :href="route('drive.earnings', ['period' => $period, 'date' => $previous])" />
            <span class="dr-periodnav__label">{{ $label }}</span>
            @if ($isCurrent)
                <span style="width:44px" aria-hidden="true"></span>
            @else
                <x-eh.icon-button icon="chevron-right" :label="'Next '.$period" :href="route('drive.earnings', ['period' => $period, 'date' => $next])" />
            @endif
        </nav>

        <section class="dr-figure" aria-labelledby="net-h">
            <h2 class="eh-sr-only" id="net-h">Net earnings</h2>
            <x-eh.money :minor="$t['net_minor']" :currency="$cur" class="dr-figure__value" />
            <p class="dr-figure__line">Net after commission and deductions, from {{ $t['trips'] }} {{ \Illuminate\Support\Str::plural('trip', $t['trips']) }}.</p>
        </section>

        <section class="dr-panel" aria-labelledby="split-h">
            <h2 id="split-h">How it adds up</h2>
            <dl class="dr-ledger">
                <div><dt>Gross fares</dt><dd><x-eh.money :minor="$t['fare_minor']" :currency="$cur" /></dd></div>
                <div><dt>Commission</dt><dd><x-eh.money :minor="-$t['commission_minor']" :currency="$cur" /></dd></div>
                @if ($other !== 0)
                    <div><dt>Platform fees, tax and other deductions</dt><dd><x-eh.money :minor="-$other" :currency="$cur" /></dd></div>
                @endif
                @if ($t['incentives_minor'])<div><dt>Incentives</dt><dd><x-eh.money :minor="$t['incentives_minor']" :currency="$cur" /></dd></div>@endif
                @if ($t['cancellation_fees_minor'])<div><dt>Cancellation fees</dt><dd><x-eh.money :minor="$t['cancellation_fees_minor']" :currency="$cur" /></dd></div>@endif
                @if ($t['adjustments_minor'])<div><dt>Adjustments</dt><dd><x-eh.money :minor="$t['adjustments_minor']" :currency="$cur" /></dd></div>@endif
                <div class="is-total"><dt>Net</dt><dd><x-eh.money :minor="$t['net_minor']" :currency="$cur" /></dd></div>
            </dl>
            <dl class="dr-ledger">
                <div><dt>Cash you collected</dt><dd><x-eh.money :minor="$t['cash_collected_minor']" :currency="$cur" /></dd></div>
                <div><dt>Trips</dt><dd>{{ $t['trips'] }}</dd></div>
                @if ($statement['averages']['net_per_trip_minor'] !== null)
                    <div><dt>Net per trip</dt><dd><x-eh.money :minor="$statement['averages']['net_per_trip_minor']" :currency="$cur" /></dd></div>
                @endif
                @if ($onlineSeconds !== null)
                    <div><dt>Time online</dt><dd>{{ $hours }} h {{ $mins }} min</dd></div>
                @endif
                <div><dt>Pending (payment not settled)</dt><dd><x-eh.money :minor="$t['pending_minor']" :currency="$cur" /></dd></div>
                <div><dt>Available for payout</dt><dd><x-eh.money :minor="$t['available_minor']" :currency="$cur" /></dd></div>
                <div><dt>Already paid out</dt><dd><x-eh.money :minor="$t['paid_minor']" :currency="$cur" /></dd></div>
            </dl>
            <p class="dr-note">Pending earnings become available once the trip's payment settles. Commission is charged on the trip fare only; tolls are paid back to you at cost.@if($onlineSeconds === null) Time online is not shown because it is not measured yet.@endif</p>
            <div class="dr-actions__row">
                <x-eh.button variant="secondary" size="driver" :href="route('drive.earnings.payouts')" icon="wallet">Payouts and bank account</x-eh.button>
                <x-eh.button variant="ghost" size="driver" :href="route('money.driver.earnings.csv', ['period' => $period, 'date' => $date])" icon="download">Statement CSV</x-eh.button>
            </div>
        </section>

        <section class="dr-section" aria-labelledby="rows-h">
            <h2 id="rows-h">Each trip and payment</h2>
            @if (count($statement['earnings']) === 0)
                <x-eh.empty title="Nothing earned in this period" :pending="false" :heading-level="3">
                    <p style="margin:0">Completed trips appear here with the fare, the commission and what you keep.</p>
                </x-eh.empty>
            @else
                <ul class="eh-list">
                    @foreach ($statement['earnings'] as $row)
                        @php($ride = $row['ride_id'] ? $rides->get($row['ride_id']) : null)
                        <li>
                            <a class="dr-trip-row" href="{{ $ride ? route('drive.trips.show', $ride) : '#' }}" @unless($ride) aria-disabled="true" @endunless>
                                <span class="dr-trip-row__title">{{ $ride ? \Illuminate\Support\Str::limit($ride->dropoff_address, 48) : $kinds[$row['kind']] ?? $row['kind'] }}</span>
                                <span class="dr-trip-row__meta">{{ $kinds[$row['kind']] ?? $row['kind'] }}, {{ \App\Http\Controllers\Driver\Support\Local::at($row['earned_at'])->isoFormat('D MMM HH:mm') }}. Fare {{ \App\Support\Ui\MoneyFormatter::format((int) $row['fare_minor'], $row['currency']) }}@if($row['commission_minor']), commission {{ \App\Support\Ui\MoneyFormatter::format((int) $row['commission_minor'], $row['currency']) }}@endif{{ $row['cash_collected_minor'] ? ', cash '.\App\Support\Ui\MoneyFormatter::format((int) $row['cash_collected_minor'], $row['currency']) : '' }}</span>
                                <span class="dr-trip-row__amount"><x-eh.money :minor="$row['net_minor']" :currency="$row['currency']" /><x-eh.badge :tone="$statusTone[$row['status']] ?? 'neutral'">{{ $statusWord[$row['status']] ?? $row['status'] }}</x-eh.badge></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="dr-section" aria-labelledby="inc-h">
            <h2 id="inc-h">Incentives</h2>
            @forelse ($incentives as $program)
                <div class="dr-panel">
                    <h3>{{ $program['name'] }}</h3>
                    <p class="dr-note">Reward <x-eh.money :minor="$program['reward_minor']" :currency="$program['currency']" />, {{ \App\Http\Controllers\Driver\Support\Local::at($program['window_start'])->isoFormat('D MMM') }} to {{ \App\Http\Controllers\Driver\Support\Local::at($program['window_end'])->isoFormat('D MMM') }}.</p>
                    @if ($program['state'] === 'unmeasurable')
                        <p class="dr-note">This programme needs data Dot.Ehail does not record yet, so it cannot be earned. It is shown so you know it exists.</p>
                    @else
                        <div class="dr-bar" role="img" aria-label="{{ (int) $program['percent'] }} percent done"><span style="width: {{ min(100, max(0, (int) $program['percent'])) }}%"></span></div>
                        <p class="dr-note">{{ match ($program['state']) { 'earned' => 'Earned. It is in your statement.', 'rules_met_awaiting_award' => 'Rules met. The reward is added at the next hourly check.', 'budget_exhausted' => 'The programme budget ran out before your reward.', 'paused' => 'Paused by the operator.', default => 'In progress: '.(int) $program['percent'].'% of the way.' } }}</p>
                    @endif
                </div>
            @empty
                <p class="dr-note">No active programmes. When the operator runs one, its rules and your progress appear here.</p>
            @endforelse
        </section>

        <p class="dr-note">Next payout batch is drafted {{ \App\Http\Controllers\Driver\Support\Local::at($nextPayout)->isoFormat('dddd D MMMM [at] HH:mm') }}. Money moves once operations pays the batch.</p>
    </div>
</x-driver-layout>
