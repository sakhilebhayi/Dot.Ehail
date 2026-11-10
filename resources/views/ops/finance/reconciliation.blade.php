@php($m = $report['mismatches'] ?? [])
<x-ops-layout title="Reconciliation">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Finance</h1>
                <p class="ops-head__finding" @if(count($m) > 0) data-tone="stop" @endif>{{ count($m) === 0 ? 'Every verified payment, refund and payout from '.$from->format('d M').' to '.$to->format('d M').' is posted, and every journal balances.' : count($m).' '.\Illuminate\Support\Str::plural('mismatch', count($m)).' between payments and the ledger.' }}</p>
            </div>
        </header>
        @include('ops.finance.tabs', ['active' => 'reconciliation'])
        <form class="ops-filter" method="GET" action="{{ route('ops.finance.reconciliation') }}">
            <x-eh.field label="From" name="from" type="date" :value="$from->toDateString()" />
            <x-eh.field label="To" name="to" type="date" :value="$to->toDateString()" />
            <x-eh.select label="Issues" name="issues" :options="['open' => 'Open', 'resolved' => 'Resolved', 'all' => 'All']" :value="$issueStatus" />
            <x-eh.button type="submit" size="sm" icon="refresh">Run</x-eh.button>
        </form>
        <dl class="ops-figures" aria-label="Verified payments">
            <div><dt>Paystack payments verified</dt><dd>{{ $report['verified_payments']['paystack_count'] ?? 0 }}<small><x-eh.money :minor="(int) ($report['verified_payments']['paystack_minor'] ?? 0)" :currency="$report['currency']" /></small></dd></div>
            <div><dt>Cash confirmed</dt><dd>{{ $report['verified_payments']['cash_count'] ?? 0 }}<small><x-eh.money :minor="(int) ($report['verified_payments']['cash_minor'] ?? 0)" :currency="$report['currency']" /></small></dd></div>
            <div><dt>Ledger debits, all time</dt><dd><x-eh.money :minor="(int) ($report['ledger']['total_debits_minor'] ?? 0)" :currency="$report['currency']" /></dd></div>
            <div><dt>Ledger credits, all time</dt><dd><x-eh.money :minor="(int) ($report['ledger']['total_credits_minor'] ?? 0)" :currency="$report['currency']" /></dd></div>
            <div data-tone="{{ count($m) ? 'stop' : '' }}"><dt>Mismatches</dt><dd>{{ count($m) }}</dd></div>
        </dl>
        <div class="ops-cols ops-cols--2">
            <section class="ops-panel ops-panel--flush" aria-labelledby="mm-h">
                <div class="ops-panel__head"><h2 id="mm-h">Exceptions</h2><span class="ops-panel__meta">payments and ledger in this period</span></div>
                @if ($m === [])
                    <p class="ops-none">No exception.</p>
                @else
                    <table class="ops-table ops-table--compact">
                        <thead><tr><th>What</th><th>Record</th><th class="num">Amount</th></tr></thead>
                        <tbody>
                            @foreach ($m as $row)
                                <tr><td>{{ ucfirst(str_replace('_', ' ', $row['kind'])) }}</td><td>@if(isset($row['payment_id']))<a href="{{ route('ops.finance.payment', $row['payment_id']) }}">{{ $row['reference'] ?? 'payment '.$row['payment_id'] }}</a>@elseif(isset($row['refund_id']))refund {{ $row['refund_id'] }}@elseif(isset($row['payout_id']))payout {{ $row['payout_id'] }}@else journal {{ $row['journal_id'] ?? '' }}@endif</td><td class="num">@isset($row['amount_minor'])<x-eh.money :minor="(int) $row['amount_minor']" :currency="$report['currency']" />@endisset</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
            <section class="ops-panel ops-panel--flush" aria-labelledby="iss-h">
                <div class="ops-panel__head"><h2 id="iss-h">Finance issues</h2><span class="ops-panel__meta">raised by the money domain</span></div>
                @if ($issues->isEmpty())
                    <p class="ops-none">No {{ $issueStatus === 'all' ? '' : $issueStatus.' ' }}issue.</p>
                @else
                    <ul class="ops-queue">
                        @foreach ($issues as $issue)
                            <li style="grid-template-columns:minmax(0,1fr)">
                                <span class="ops-queue__title">{{ $issue->kindLabel() }} <x-eh.badge :tone="$issue->severity === 'critical' ? 'stop' : 'caution'">{{ $issue->severity }}</x-eh.badge></span>
                                <span class="ops-queue__meta">{{ $issue->summary }}@if($issue->amount_minor) · <x-eh.money :minor="$issue->amount_minor" :currency="$issue->currency ?? 'ZAR'" />@endif @if($issue->ride_id) · ride {{ $issue->ride_id }}@endif · {{ $issue->created_at?->format('d M H:i') }}</span>
                                @if ($issue->status === 'open' && $canManage)
                                    <form class="ops-act" data-ops-action method="post" action="{{ route('money.ops.issues.resolve', $issue->id) }}" data-ops-done="Issue resolved." style="margin-top:var(--eh-space-2)"><div class="ops-act__row"><label class="eh-sr-only" for="iss-{{ $issue->id }}">How it was resolved</label><input id="iss-{{ $issue->id }}" class="eh-input" name="note" required maxlength="500" placeholder="How it was resolved" style="flex:1 1 10rem"><x-eh.button type="submit" size="sm" variant="secondary">Resolve</x-eh.button></div></form>
                                @elseif ($issue->resolution_note)
                                    <span class="ops-queue__meta">Resolved: {{ $issue->resolution_note }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    @include('ops.partials.pager', ['page' => $issues])
                @endif
            </section>
        </div>
    </div>
</x-ops-layout>
