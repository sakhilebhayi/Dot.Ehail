@php($cur = $payment->currency ?? 'ZAR')
<x-ops-layout :title="'Payment '.$payment->reference">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <p class="ops-crumb"><a href="{{ route('ops.finance') }}">Finance</a> / {{ $payment->reference }}</p>
        <header class="ops-head">
            <div class="ops-head__text">
                <h1><x-eh.money :minor="$payment->amountMinor()" :currency="$cur" size="lg" /> <x-eh.badge :tone="match($payment->status){'paid'=>'go','failed','unmatched'=>'stop',default=>'caution'}">{{ str_replace('_', ' ', $payment->status) }}</x-eh.badge></h1>
                <p class="ops-head__finding">{{ str_replace('_', ' ', (string) ($payment->purpose ?? 'ride fare')) }} from {{ $payment->payer?->name }} · {{ $payment->provider }} · attempt {{ $payment->attempt ?? 1 }}{{ $payment->paid_at ? ' · paid '.$payment->paid_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i') : '' }}{{ $payment->verified_at ? ', verified '.$payment->verified_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('H:i') : '' }}</p>
            </div>
        </header>
        <div class="ops-split">
            <div class="ops-stack">
                <section class="ops-panel" aria-labelledby="pf-h">
                    <div class="ops-panel__head"><h2 id="pf-h">Payment</h2></div>
                    <dl class="ops-facts">
                        <dt>Reference</dt><dd>{{ $payment->reference }}</dd>
                        @if ($payment->ride)<dt>Ride</dt><dd>@can('ops.rides.view')<a class="eh-link" href="{{ route('ops.rides.show', $payment->ride_id) }}">Ride {{ $payment->ride_id }}</a>@else Ride {{ $payment->ride_id }} @endcan · {{ $payment->ride->pickup_address }} to {{ $payment->ride->dropoff_address }}</dd>@endif
                        <dt>Channel</dt><dd>{{ $payment->channel ?? '-' }}</dd>
                        @if ($payment->failure_code || $payment->failure_message)<dt>Failure</dt><dd>{{ $payment->failure_code }} {{ $payment->failure_message }}</dd>@endif
                        @if ($payment->next_retry_at)<dt>Next retry</dt><dd class="num">{{ $payment->next_retry_at->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i') }}</dd>@endif
                        @if ($payment->unmatched_reason)<dt>Unmatched</dt><dd>{{ $payment->unmatched_reason }}</dd>@endif
                        <dt>Refunded so far</dt><dd class="num"><x-eh.money :minor="(int) $payment->refunded_minor" :currency="$cur" /></dd>
                    </dl>
                </section>
                <section class="ops-panel ops-panel--flush" aria-labelledby="rf-h">
                    <div class="ops-panel__head"><h2 id="rf-h">Refunds</h2></div>
                    @if ($payment->refunds->isEmpty())
                        <p class="ops-none">No refund.</p>
                    @else
                        <table class="ops-table ops-table--compact">
                            <thead><tr><th>Reason</th><th>Status</th><th>Method</th><th class="num">Amount</th><th class="num">Requested</th></tr></thead>
                            <tbody>
                                @foreach ($payment->refunds as $r)
                                    <tr><td>{{ \App\Domain\Payments\Models\Refund::REASONS[$r->reason] ?? $r->reason }}@if($r->note)<span class="sub">{{ $r->note }}</span>@endif</td><td>{{ $r->status }}@if($r->failure_message)<span class="sub">{{ $r->failure_message }}</span>@endif</td><td>{{ $r->method }}</td><td class="num"><x-eh.money :minor="(int) $r->amount_minor" :currency="$r->currency" /></td><td class="num">{{ $r->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i') }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
                <section class="ops-panel ops-panel--flush" aria-labelledby="jr-h">
                    <div class="ops-panel__head"><h2 id="jr-h">Ledger postings</h2></div>
                    @forelse ($journals as $j)
                        @include('ops.finance.journal', ['j' => $j])
                    @empty
                        <p class="ops-none">Nothing posted for this payment.</p>
                    @endforelse
                </section>
            </div>
            @if ($canRefund && $payment->status === 'paid' && $refundable > 0)
                <section class="ops-panel" aria-labelledby="refund-h">
                    <div class="ops-panel__head"><h2 id="refund-h">Refund</h2><span class="ops-panel__meta">up to <x-eh.money :minor="$refundable" :currency="$cur" /></span></div>
                    <form class="ops-act" data-ops-action method="post" action="{{ route('money.ops.refund', $payment->id) }}" data-ops-confirm="Send this refund? Card refunds go to Paystack and count as done only when Paystack says so." data-ops-done="Refund requested.">
                        <x-eh.field label="Amount (rand)" name="amount_rand" inputmode="decimal" data-ops-minor="amount_minor" required id="rf-amount" />
                        <x-eh.select label="Reason" name="reason" :options="$reasons" id="rf-reason" />
                        <x-eh.field label="Note (needed for Other)" name="note" type="textarea" maxlength="500" id="rf-note" :optional="true" />
                        <input type="hidden" name="charge_back_driver" value="0">
                        <label class="ops-check"><input type="checkbox" name="charge_back_driver" value="1"> Recover it from the driver's earnings for this trip</label>
                        <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="danger">Refund</x-eh.button></div>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-ops-layout>
