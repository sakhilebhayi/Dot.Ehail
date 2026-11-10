{{-- One ledger journal: its lines, debits and credits, which always balance. --}}
<div style="padding:var(--eh-space-2) 0 var(--eh-space-3)">
    <p class="ops-note ops-note--small" style="padding:0 var(--eh-space-5)"><strong style="color:var(--eh-ink)">{{ str_replace('_', ' ', $j->kind) }}</strong> · {{ $j->posted_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y H:i') }} · {{ $j->reason }}</p>
    <table class="ops-table ops-table--compact">
        <thead><tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>
        <tbody>
            @foreach ($j->entries as $e)
                <tr><td>{{ \App\Domain\Ledger\Account::label($e->account) }}<span class="sub">{{ $e->account }}</span></td><td class="num">@if($e->debit_minor)<x-eh.money :minor="(int) $e->debit_minor" :currency="$e->currency" />@endif</td><td class="num">@if($e->credit_minor)<x-eh.money :minor="(int) $e->credit_minor" :currency="$e->currency" />@endif</td></tr>
            @endforeach
        </tbody>
    </table>
</div>
