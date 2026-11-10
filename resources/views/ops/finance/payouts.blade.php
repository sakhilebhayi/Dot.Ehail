<x-ops-layout title="Payouts">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Finance</h1>
                <p class="ops-head__finding">Batches are drafted weekly; money moves only when someone pays the bank file and marks it paid{{ $transfersOn ? ', or sends Paystack transfers' : ' (Paystack transfers are switched off)' }}.</p>
            </div>
        </header>
        @include('ops.finance.tabs', ['active' => 'payouts'])
        <div class="ops-split">
            <section class="ops-panel ops-panel--flush" aria-label="Payout batches">
                @if ($batches->isEmpty())
                    <p class="ops-none" style="padding:var(--eh-space-5)">No payout batch has been drafted.</p>
                @else
                    <div class="ops-tablewrap"><table class="ops-table">
                        <thead><tr><th>Batch</th><th>Status</th><th class="num">Payouts</th><th class="num">Total</th><th class="num">Exported</th><th class="num">Completed</th></tr></thead>
                        <tbody>
                            @foreach ($batches as $b)
                                <tr>
                                    <td><a href="{{ route('ops.finance.batch', $b->id) }}">Earnings to {{ $b->period_end?->format('d M Y') }}</a><span class="sub">{{ $b->method }}</span></td>
                                    <td><x-eh.badge :tone="match($b->status){'completed','paid'=>'go','failed'=>'stop','draft'=>'info',default=>'caution'}">{{ str_replace('_', ' ', $b->status) }}</x-eh.badge></td>
                                    <td class="num">{{ $b->payout_count }}</td>
                                    <td class="num"><x-eh.money :minor="(int) $b->total_minor" :currency="$b->currency" /></td>
                                    <td class="num">{{ $b->exported_at?->format('d M H:i') ?? '-' }}</td>
                                    <td class="num">{{ $b->completed_at?->format('d M H:i') ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table></div>
                    @include('ops.partials.pager', ['page' => $batches])
                @endif
            </section>
            @if ($canPayout)
                <section class="ops-panel" aria-labelledby="draft-h">
                    <div class="ops-panel__head"><h2 id="draft-h">Draft a batch</h2></div>
                    <form class="ops-act" data-ops-action method="post" action="{{ route('money.ops.batches.draft') }}" data-ops-done="Batch drafted.">
                        <x-eh.field label="Include earnings up to (a past date)" name="period_end" type="date" :value="now()->subDay()->toDateString()" required id="draft-end" />
                        <p class="ops-note ops-note--small">Available earnings up to the end of that day, minus cash drivers hold; below the minimum or without a bank account, a payee is carried forward with the reason.</p>
                        <div class="ops-act__row"><x-eh.button type="submit" size="sm">Draft batch</x-eh.button></div>
                    </form>
                </section>
            @endif
        </div>
    </div>
</x-ops-layout>
