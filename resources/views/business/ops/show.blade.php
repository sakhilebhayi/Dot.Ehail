<x-ops-layout :title="$account->displayName()">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    @php
        $cur = $account->currency;
        $rand = fn (int $minor) => number_format($minor / 100, 2, '.', '');
    @endphp
    <div class="bz">
        <div class="bz-title">
            <div class="bz-title__text">
                <h1 class="eh-h1" style="margin:0">{{ $account->displayName() }}</h1>
                <p class="bz-title__sub"><x-eh.badge :tone="$account->statusTone()">{{ $account->statusLabel() }}</x-eh.badge> Applied {{ $account->created_at->format('j M Y') }}@if ($account->reviewed_at), reviewed {{ $account->reviewed_at->format('j M Y') }}@endif. @if ($account->status_note) {{ $account->status_note }} @endif</p>
            </div>
            <a class="eh-link" href="{{ route('ops.business') }}">All business accounts</a>
        </div>

        <div class="bz-grid">
            <div class="bz">
                <section class="bz-panel" aria-labelledby="ops-co">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="ops-co">What they told us</h2><p class="bz-panel__caption">Check against CIPC before approving</p></div>
                    <dl class="bz-facts">
                        <div><dt>Registered name</dt><dd>{{ $account->legal_name }}</dd></div>
                        <div><dt>Trading name</dt><dd>{{ $account->trading_name ?: 'None' }}</dd></div>
                        <div><dt>Registration number</dt><dd>{{ $account->registration_number }}</dd></div>
                        <div><dt>VAT number</dt><dd>{{ $account->vat_number ?: 'None' }}</dd></div>
                        <div><dt>Billing email</dt><dd>{{ $account->billing_email }}</dd></div>
                        <div><dt>Address</dt><dd>{{ collect([$account->address_line1, $account->address_line2, $account->city, $account->postal_code, $account->country])->filter()->implode(', ') }}</dd></div>
                        <div><dt>InfoDot company</dt><dd>{{ $account->team ? $account->team->name.($account->team->infodot_team_uuid ? ' (linked)' : '') : 'Not linked' }}</dd></div>
                        <div><dt>Account admins</dt><dd>{{ $admins->map(fn ($m) => $m->displayName().' <'.($m->user?->email ?? $m->email).'>')->implode(', ') }}</dd></div>
                        <div><dt>Wants to pay by</dt><dd>{{ $account->billing_mode === 'card' ? 'Company card' : 'Monthly invoice (EFT)' }}</dd></div>
                    </dl>
                </section>

                <section class="bz-panel bz-panel--flush" aria-labelledby="ops-st">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="ops-st">Statements and payments</h2></div>
                    @if ($statements->isEmpty())
                        <div style="padding:0 var(--eh-space-5) var(--eh-space-5)"><p class="bz-hint">No statements yet.</p></div>
                    @else
                        <div class="bz-tablewrap"><table class="bz-table">
                            <thead><tr><th scope="col">Statement</th><th scope="col">Due</th><th scope="col">State</th><th scope="col" class="num">Total</th><th scope="col" class="num">Still owed</th></tr></thead>
                            <tbody>@foreach ($statements as $s)<tr><td>{{ $s->number }}</td><td>{{ $s->due_at->format('j M Y') }}</td><td><x-eh.badge :tone="$s->statusTone()">{{ $s->statusLabel() }}</x-eh.badge></td><td class="num"><x-eh.money :minor="$s->closing_minor" :currency="$cur" /></td><td class="num"><x-eh.money :minor="$owed($s)" :currency="$cur" /></td></tr>@endforeach</tbody>
                        </table></div>
                    @endif
                    @if ($payments->isNotEmpty())
                        <div style="padding:var(--eh-space-5)"><dl class="bz-facts">@foreach ($payments as $p)<div><dt>{{ ($p->paid_at ?? $p->created_at)->format('j M Y') }} · {{ strtoupper($p->method) }} · {{ $p->reference }} · {{ $p->status }}</dt><dd><x-eh.money :minor="$p->amount_minor" :currency="$p->currency" /></dd></div>@endforeach</dl></div>
                    @endif
                </section>
            </div>

            <div class="bz">
                <section class="bz-panel" aria-labelledby="ops-credit">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="ops-credit">Credit</h2></div>
                    <dl class="bz-facts">
                        <div><dt>Owed now (ledger)</dt><dd><x-eh.money :minor="$outstanding" :currency="$cur" /></dd></div>
                        <div><dt>Available for new rides</dt><dd><x-eh.money :minor="max(0, $creditAvailable)" :currency="$cur" /></dd></div>
                    </dl>
                    @if ($account->status === 'pending_review')
                        <form class="bz-form" method="POST" action="{{ route('ops.business.approve', $account->id) }}" style="margin-top:var(--eh-space-5)">
                            @csrf
                            <x-eh.field label="Credit limit (R)" name="credit_limit" required inputmode="decimal" hint="The most the company may owe at once, including rides booked" />
                            <div class="bz-form__row">
                                <x-eh.field label="Payment terms (days)" name="terms_days" type="number" value="30" min="0" max="90" required />
                                <x-eh.field label="Grace before pausing (days)" name="grace_days" type="number" value="7" min="0" max="60" required />
                            </div>
                            <x-eh.field label="Note" name="note" optional />
                            <div class="bz-form__actions"><x-eh.button type="submit" icon="check">Approve account</x-eh.button></div>
                        </form>
                        <form class="bz-form" method="POST" action="{{ route('ops.business.decline', $account->id) }}" style="margin-top:var(--eh-space-6)">
                            @csrf
                            <x-eh.field label="Reason for declining" name="note" id="decline-note" required hint="The applicant sees this" />
                            <div class="bz-form__actions"><x-eh.button type="submit" variant="danger">Decline</x-eh.button></div>
                        </form>
                    @else
                        <form class="bz-form" method="POST" action="{{ route('ops.business.terms', $account->id) }}" style="margin-top:var(--eh-space-5)">
                            @csrf @method('PATCH')
                            <x-eh.field label="Credit limit (R)" name="credit_limit" :value="$rand((int) $account->credit_limit_minor)" required inputmode="decimal" />
                            <div class="bz-form__row">
                                <x-eh.field label="Payment terms (days)" name="terms_days" type="number" :value="$account->terms_days" min="0" max="90" required />
                                <x-eh.field label="Grace (days)" name="grace_days" type="number" :value="$account->grace_days" min="0" max="60" required />
                            </div>
                            <div class="bz-form__actions"><x-eh.button type="submit" variant="secondary">Save terms</x-eh.button></div>
                        </form>
                    @endif
                </section>

                @if (in_array($account->status, ['active', 'suspended'], true))
                    <section class="bz-panel" aria-labelledby="ops-eft">
                        <div class="bz-panel__head"><h2 class="bz-panel__title" id="ops-eft">Record an EFT received</h2><p class="bz-panel__caption">Posts to the ledger at once</p></div>
                        <form class="bz-form" method="POST" action="{{ route('ops.business.payments', $account->id) }}">
                            @csrf
                            <div class="bz-form__row">
                                <x-eh.field label="Amount (R)" name="amount" required inputmode="decimal" />
                                <x-eh.field label="Bank reference" name="reference" required />
                            </div>
                            <x-eh.field label="Note" name="note" id="eft-note" optional />
                            <div class="bz-form__actions"><x-eh.button type="submit">Record payment</x-eh.button></div>
                        </form>
                    </section>
                    <section class="bz-panel" aria-labelledby="ops-state">
                        <div class="bz-panel__head"><h2 class="bz-panel__title" id="ops-state">Account state</h2></div>
                        @if ($account->status === 'active')
                            <form class="bz-form" method="POST" action="{{ route('ops.business.suspend', $account->id) }}">
                                @csrf
                                <x-eh.field label="Why pause new rides" name="note" id="suspend-note" required hint="Trips already booked still complete and are billed" />
                                <div class="bz-form__actions"><x-eh.button type="submit" variant="danger">Suspend for new rides</x-eh.button></div>
                            </form>
                        @else
                            <form method="POST" action="{{ route('ops.business.reactivate', $account->id) }}">@csrf<x-eh.button type="submit">Reactivate</x-eh.button></form>
                        @endif
                        <form class="bz-form" method="POST" action="{{ route('ops.business.statements', $account->id) }}" style="margin-top:var(--eh-space-6)">
                            @csrf
                            <x-eh.field label="Issue a missed statement for (YYYY-MM)" name="period" :value="$lastMonth" required />
                            <div class="bz-form__actions"><x-eh.button type="submit" variant="secondary">Issue statement</x-eh.button></div>
                        </form>
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-ops-layout>
