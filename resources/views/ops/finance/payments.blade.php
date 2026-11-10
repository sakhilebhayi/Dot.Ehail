<x-ops-layout title="Finance">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Finance</h1>
                <p class="ops-head__finding" @if($openIssues > 0) data-tone="caution" @endif>{{ number_format($payments->total()) }} {{ \Illuminate\Support\Str::plural('payment attempt', $payments->total()) }} match. {{ $openIssues }} open finance {{ \Illuminate\Support\Str::plural('issue', $openIssues) }}. A payment counts as paid only when the gateway's verify answer matched.</p>
            </div>
        </header>
        @include('ops.finance.tabs', ['active' => 'payments'])
        <form class="ops-filter" method="GET" action="{{ route('ops.finance') }}" role="search" aria-label="Filter payments">
            <x-eh.field label="From" name="from" type="date" :value="$filters['from'] ?? null" />
            <x-eh.field label="To" name="to" type="date" :value="$filters['to'] ?? null" />
            <x-eh.select label="Status" name="status" :options="['initiated' => 'Checkout opened', 'pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'expired' => 'Expired', 'unmatched' => 'Unmatched', 'awaiting_cash' => 'Awaiting cash']" placeholder="Any status" :value="$filters['status'] ?? ''" />
            <div class="eh-field eh-field--grow"><label class="eh-field__label" for="f-pq">Reference or ride number</label><input id="f-pq" class="eh-input" type="search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="80"></div>
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>
        <section class="ops-panel ops-panel--flush" aria-label="Payments">
            @if ($payments->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">No payment matches these filters.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Reference</th><th>Purpose</th><th>Payer</th><th>Status</th><th>Method</th><th class="num">Amount</th><th class="num">Refunded</th><th class="num">Created</th></tr></thead>
                    <tbody>
                        @foreach ($payments as $p)
                            <tr>
                                <td><a href="{{ route('ops.finance.payment', $p->id) }}">{{ $p->reference }}</a>@if($p->ride_id)<span class="sub">ride {{ $p->ride_id }}</span>@endif</td>
                                <td>{{ str_replace('_', ' ', (string) ($p->purpose ?? 'ride_fare')) }}</td>
                                <td>{{ $p->payer?->name }}</td>
                                <td><x-eh.badge :tone="match($p->status){'paid'=>'go','failed','unmatched'=>'stop','pending','awaiting_cash','initiated'=>'caution',default=>'neutral'}">{{ str_replace('_', ' ', $p->status) }}</x-eh.badge></td>
                                <td>{{ $p->provider }}{{ $p->channel ? ' · '.$p->channel : '' }}</td>
                                <td class="num"><x-eh.money :minor="$p->amountMinor()" :currency="$p->currency ?? 'ZAR'" /></td>
                                <td class="num">@if($p->refunded_minor)<x-eh.money :minor="(int) $p->refunded_minor" :currency="$p->currency ?? 'ZAR'" />@else<span class="ops-dash">-</span>@endif</td>
                                <td class="num">{{ $p->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
                @include('ops.partials.pager', ['page' => $payments])
            @endif
        </section>
    </div>
</x-ops-layout>
