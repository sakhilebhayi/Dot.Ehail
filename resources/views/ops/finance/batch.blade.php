@php($open = $batch->payouts->whereNotIn('status', ['paid', 'failed', 'reversed', 'settled']))
<x-ops-layout :title="'Payout batch '.$batch->id">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <p class="ops-crumb"><a href="{{ route('ops.finance.payouts') }}">Payouts</a> / earnings to {{ $batch->period_end?->format('d M Y') }}</p>
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Payout batch {{ $batch->id }} <x-eh.badge :tone="match($batch->status){'completed','paid'=>'go','failed'=>'stop','draft'=>'info',default=>'caution'}">{{ str_replace('_', ' ', $batch->status) }}</x-eh.badge></h1>
                <p class="ops-head__finding">{{ $batch->payout_count }} {{ \Illuminate\Support\Str::plural('payout', $batch->payout_count) }}, <x-eh.money :minor="(int) $batch->total_minor" :currency="$batch->currency" /> in all. {{ $open->count() }} not yet settled.</p>
            </div>
            @if ($canPayout)
                <div class="ops-head__actions">
                    <x-eh.button size="sm" variant="secondary" :href="route('money.ops.batch.export', $batch->id)" icon="download">Bank file (CSV)</x-eh.button>
                    @if ($open->isNotEmpty())
                        <form data-ops-action method="post" action="{{ route('money.ops.batch.paid', $batch->id) }}" data-ops-confirm="Mark every open payout in this batch as paid? Do this only after the bank payment went through." data-ops-done="Batch marked paid." style="margin:0"><x-eh.button type="submit" size="sm" icon="check">Mark all paid</x-eh.button></form>
                        @if ($transfersOn)<form data-ops-action method="post" action="{{ route('money.ops.batch.transfers', $batch->id) }}" data-ops-confirm="Send these payouts through Paystack transfers now?" data-ops-done="Transfers sent." style="margin:0"><x-eh.button type="submit" size="sm" variant="secondary">Send Paystack transfers</x-eh.button></form>@endif
                    @endif
                </div>
            @endif
        </header>
        @if (! empty($batch->summary['carried_forward']))<p class="ops-note" style="margin-bottom:var(--eh-space-4)">{{ count($batch->summary['carried_forward']) }} {{ \Illuminate\Support\Str::plural('payee', count($batch->summary['carried_forward'])) }} carried forward (below the minimum or no bank account).</p>@endif
        <section class="ops-panel ops-panel--flush" aria-label="Payouts">
            @if ($batch->payouts->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">This batch has no payouts.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Payee</th><th>Status</th><th class="num">Earnings</th><th class="num">Cash netted</th><th class="num">To pay</th><th>Act</th></tr></thead>
                    <tbody>
                        @foreach ($batch->payouts->sortBy('id') as $p)
                            <tr>
                                <td>@can('ops.drivers.view')<a href="{{ route('ops.drivers.show', $p->payee_user_id) }}">{{ $p->payee?->name }}</a>@else{{ $p->payee?->name }}@endcan<span class="sub">{{ $p->payee_type }} · {{ $p->reference }}</span></td>
                                <td>{{ str_replace('_', ' ', $p->status) }}@if($p->failure_reason)<span class="sub">{{ $p->failure_reason }}</span>@endif</td>
                                <td class="num"><x-eh.money :minor="$p->earnings_minor" :currency="$p->currency" /></td>
                                <td class="num"><x-eh.money :minor="$p->cash_offset_minor" :currency="$p->currency" /></td>
                                <td class="num"><strong><x-eh.money :minor="$p->amount_minor" :currency="$p->currency" /></strong></td>
                                <td>
                                    @if ($canPayout)
                                        @if (! in_array($p->status, ['paid', 'failed', 'reversed', 'settled'], true))
                                            <form class="ops-act" data-ops-action method="post" action="{{ route('money.ops.payout.fail', $p->id) }}" data-ops-done="Payout marked failed; the earnings go to the next batch."><div class="ops-act__row"><label class="eh-sr" for="pf-{{ $p->id }}">Why it failed</label><input id="pf-{{ $p->id }}" class="eh-input" name="reason" required maxlength="255" placeholder="Why it failed" style="flex:1 1 8rem"><x-eh.button type="submit" size="sm" variant="ghost">Mark failed</x-eh.button></div></form>
                                        @elseif ($p->status === 'paid')
                                            <form class="ops-act" data-ops-action method="post" action="{{ route('money.ops.payout.reverse', $p->id) }}" data-ops-confirm="Reverse this paid payout? What is owed goes back on the driver's balance." data-ops-done="Payout reversed."><div class="ops-act__row"><label class="eh-sr" for="pr-{{ $p->id }}">Why it is reversed</label><input id="pr-{{ $p->id }}" class="eh-input" name="reason" required maxlength="255" placeholder="Why" style="flex:1 1 8rem"><x-eh.button type="submit" size="sm" variant="ghost">Reverse</x-eh.button></div></form>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    </div>
</x-ops-layout>
