<x-business-layout title="Statements" :scope="$account->displayName()">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    @php($cur = $account->currency)
    <div class="bz">
        <div class="bz-title">
            <div class="bz-title__text">
                <h1 class="eh-h1" style="margin:0">Statements</h1>
                <p class="bz-title__sub">The company owes {{ \App\Support\Ui\MoneyFormatter::format($outstanding, $cur) }} today. One statement is issued at the start of each month for the month before; every figure on it comes from the Dot.Ehail ledger.</p>
            </div>
            <div class="bz-title__actions"><x-eh.button variant="secondary" icon="download" :href="route('business.invoices.month-csv', ['period' => $month])">{{ $monthLabel }} so far, CSV</x-eh.button></div>
        </div>
        @include('business.partials.state')

        <div class="bz-grid">
            <section class="bz-panel bz-panel--flush" aria-labelledby="bz-st-title">
                <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-st-title">Issued statements</h2></div>
                @if ($statements->isEmpty())
                    <div style="padding:0 var(--eh-space-5) var(--eh-space-5)"><p class="bz-hint">The first statement is issued on the 1st of the month after the account's first billed ride.</p></div>
                @else
                    <div class="bz-tablewrap"><table class="bz-table bz-table--stack">
                        <thead><tr><th scope="col">Statement</th><th scope="col">Period</th><th scope="col">Due</th><th scope="col">State</th><th scope="col" class="num">Total</th><th scope="col" class="num">Still owed</th></tr></thead>
                        <tbody>
                            @foreach ($statements as $s)
                                <tr>
                                    <td data-label="Statement"><a class="eh-link" href="{{ route('business.statements.show', $s) }}">{{ $s->number }}</a></td>
                                    <td data-label="Period">{{ $s->snapshot['period_label'] ?? $s->period }}</td>
                                    <td data-label="Due">{{ $s->due_at->format('j M Y') }}</td>
                                    <td data-label="State"><x-eh.badge :tone="$s->statusTone()">{{ $s->statusLabel() }}</x-eh.badge></td>
                                    <td class="num" data-label="Total"><x-eh.money :minor="$s->closing_minor" :currency="$s->currency" /></td>
                                    <td class="num" data-label="Still owed">@php($o = $owed($s))<x-eh.money :minor="$o" :currency="$s->currency" :muted="$o === 0" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>

            <div class="bz">
                <section class="bz-panel" aria-labelledby="bz-mtd-title">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-mtd-title">{{ $monthLabel }} so far</h2><p class="bz-panel__caption">Not a statement yet</p></div>
                    <dl class="bz-facts">
                        <div><dt>Carried in from earlier months</dt><dd><x-eh.money :minor="$current['opening']" :currency="$cur" /></dd></div>
                        <div><dt>Rides and fees billed ({{ count($current['lines']) }})</dt><dd><x-eh.money :minor="$current['charges']" :currency="$cur" /></dd></div>
                        <div><dt>Paid this month</dt><dd><x-eh.money :minor="$current['payments']" :currency="$cur" /></dd></div>
                        <div><dt>Balance now</dt><dd><x-eh.money :minor="$current['closing']" :currency="$cur" /></dd></div>
                    </dl>
                </section>
                <section class="bz-panel" aria-labelledby="bz-pay-title">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="bz-pay-title">Payments received</h2></div>
                    @if ($payments->isEmpty())
                        <p class="bz-hint">{{ $account->billing_mode === 'card' ? 'Company card charges appear here once Paystack confirms them.' : 'EFTs appear here once Dot.Ehail records them against your statement number.' }}</p>
                    @else
                        <dl class="bz-facts">
                            @foreach ($payments as $p)
                                <div><dt>{{ ($p->paid_at ?? $p->created_at)->format('j M') }} · {{ $p->method === 'card' ? 'Company card' : 'EFT' }} @if ($p->status !== 'paid')<x-eh.badge :tone="$p->status === 'failed' ? 'stop' : 'caution'">{{ $p->status === 'failed' ? 'Declined' : 'Checking' }}</x-eh.badge>@endif</dt><dd><x-eh.money :minor="$p->amount_minor" :currency="$p->currency" :muted="$p->status !== 'paid'" /></dd></div>
                            @endforeach
                        </dl>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-business-layout>
