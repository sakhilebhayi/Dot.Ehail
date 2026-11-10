@php
    use App\Domain\Ops\Insights\ColumnChart;
    use App\Support\Ui\MoneyFormatter;
    $fmt = fn (int $minor) => MoneyFormatter::format($minor, $currency);
    $short = fn (int $minor) => $minor >= 100000000 ? 'R'.number_format($minor / 100000000, 1).'m' : ($minor >= 100000 ? 'R'.number_format($minor / 100000, 0).'k' : MoneyFormatter::format($minor, $currency, false, true));
    $chart = ColumnChart::build(array_map(fn ($r) => ['label' => $r['label'], 'value' => $r['commission'] + $r['fees'], 'display' => $fmt($r['commission'] + $r['fees'])], $periods['rows']), $short);
    $byNature = $balances->groupBy('nature');
@endphp
<x-ops-layout title="Ledger">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Finance</h1>
                <p class="ops-head__finding">Platform revenue from {{ $from->format('d M') }} to {{ $to->format('d M Y') }}: {{ $fmt($periods['totals']['commission'] + $periods['totals']['fees']) }} ({{ $fmt($periods['totals']['commission']) }} commission, {{ $fmt($periods['totals']['fees']) }} fees), from {{ number_format($periods['journals']) }} {{ \Illuminate\Support\Str::plural('journal', $periods['journals']) }}. Drivers earned {{ $fmt($periods['totals']['driver_earnings']) }}.</p>
            </div>
            <div class="ops-head__actions">
                <x-eh.button size="sm" variant="secondary" :href="route('money.ops.ledger.export', ['from' => $from->toDateString(), 'to' => $to->toDateString()])" icon="download">Ledger CSV</x-eh.button>
            </div>
        </header>
        @include('ops.finance.tabs', ['active' => 'ledger'])
        <form class="ops-filter" method="GET" action="{{ route('ops.finance.ledger') }}">
            <x-eh.field label="From" name="from" type="date" :value="$from->toDateString()" />
            <x-eh.field label="To" name="to" type="date" :value="$to->toDateString()" />
            <x-eh.select label="Group by" name="grain" :options="['day' => 'Day', 'week' => 'Week', 'month' => 'Month']" :value="$grain" />
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>
        <div class="ops-split">
            <section class="ops-panel ops-metric" aria-labelledby="rev-h">
                <div class="ops-panel__head" style="margin:0"><h2 id="rev-h">Platform revenue by {{ $grain }}</h2><span class="ops-panel__meta">{{ $currency }}</span></div>
                <p class="ops-metric__def">Defined as <code>credits minus debits on platform_revenue_commission and platform_revenue_fees</code>, by the day each journal was posted (UTC). Not fares: what the platform keeps.</p>
                @include('ops.partials.columns', ['chart' => $chart, 'title' => 'Platform revenue by '.$grain, 'empty' => 'No revenue was posted in this period.'])
                <div class="ops-tablewrap"><table class="ops-table ops-table--compact">
                    <thead><tr><th>Period</th><th class="num">Commission</th><th class="num">Fees</th><th class="num" title="credits minus debits on driver and fleet payable, payouts excluded">Driver earnings</th><th class="num">Refunds</th><th class="num">Promotions</th></tr></thead>
                    <tbody>
                        @foreach ($periods['rows'] as $r)
                            <tr><td>{{ $r['label'] }}</td>@foreach (['commission', 'fees', 'driver_earnings', 'refunds', 'promotions'] as $m)<td class="num">{{ $fmt($r[$m]) }}</td>@endforeach</tr>
                        @endforeach
                        <tr><td><strong>Total</strong></td>@foreach (['commission', 'fees', 'driver_earnings', 'refunds', 'promotions'] as $m)<td class="num"><strong>{{ $fmt($periods['totals'][$m]) }}</strong></td>@endforeach</tr>
                    </tbody>
                </table></div>
            </section>
            <section class="ops-panel ops-panel--flush" aria-labelledby="bal-h">
                <div class="ops-panel__head"><h2 id="bal-h">Balances by account</h2><span class="ops-panel__meta">all time</span></div>
                @if ($balances->isEmpty())
                    <p class="ops-none">Nothing has been posted to the ledger.</p>
                @else
                    <table class="ops-table ops-table--compact">
                        <tbody>
                            @foreach (['asset' => 'Assets', 'liability' => 'Owed', 'revenue' => 'Revenue', 'expense' => 'Costs'] as $nature => $heading)
                                @if ($byNature->has($nature))
                                    <tr><th colspan="2" scope="rowgroup">{{ $heading }}</th></tr>
                                    @foreach ($byNature[$nature] as $row)
                                        <tr><td><a href="{{ route('ops.finance.ledger', ['account' => $row['account'], 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'grain' => $grain]) }}">{{ $row['label'] }}</a><span class="sub">{{ $row['account'] }}</span></td><td class="num">{{ $fmt($row['balance_minor']) }}</td></tr>
                                    @endforeach
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
        <section class="ops-panel ops-panel--flush" aria-labelledby="jr-h" style="margin-top:var(--ops-gap)">
            <div class="ops-panel__head"><h2 id="jr-h">Journals{{ $account ? ' touching '.$account : '' }}</h2><span class="ops-panel__meta">newest first; immutable</span></div>
            @forelse ($journals as $j)
                @include('ops.finance.journal', ['j' => $j])
            @empty
                <p class="ops-none">No journal in this period.</p>
            @endforelse
            @include('ops.partials.pager', ['page' => $journals])
        </section>
    </div>
</x-ops-layout>
