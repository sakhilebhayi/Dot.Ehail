<x-business-layout title="Settings" :scope="$account->displayName()">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    @php($cur = $account->currency)
    <div class="bz">
        <div class="bz-title"><div class="bz-title__text">
            <h1 class="eh-h1" style="margin:0">Settings</h1>
            <p class="bz-title__sub">{{ $can['manage_billing'] ? 'Company details on statements and how the company pays.' : 'Read only: account admins change these.' }}</p>
        </div></div>
        @include('business.partials.state')

        <div class="bz-panel">
            <section class="bz-section">
                <div class="bz-section__label"><h2>Company details</h2><p>Printed on new statements. The registered name and number were checked by Dot.Ehail; to change them, contact Dot.Ehail.</p>
                    <dl class="bz-facts" style="margin-top:var(--eh-space-4)">
                        <div><dt>Registered name</dt><dd>{{ $account->legal_name }}</dd></div>
                        <div><dt>Registration</dt><dd>{{ $account->registration_number }}</dd></div>
                        <div><dt>InfoDot company</dt><dd>{{ $account->team?->name ?? 'Not linked' }}</dd></div>
                    </dl>
                </div>
                @if ($can['manage_billing'])
                    <form class="bz-form" method="POST" action="{{ route('business.settings.update') }}">
                        @csrf @method('PATCH')
                        <div class="bz-form__row">
                            <x-eh.field label="Trading name" name="trading_name" :value="$account->trading_name" optional />
                            <x-eh.field label="VAT number" name="vat_number" :value="$account->vat_number" optional inputmode="numeric" />
                        </div>
                        <x-eh.field label="Billing email" name="billing_email" type="email" :value="$account->billing_email" required />
                        <x-eh.field label="Street address" name="address_line1" :value="$account->address_line1" required />
                        <x-eh.field label="Address line 2" name="address_line2" :value="$account->address_line2" optional />
                        <div class="bz-form__row">
                            <x-eh.field label="City" name="city" :value="$account->city" required />
                            <x-eh.field label="Postal code" name="postal_code" :value="$account->postal_code" optional />
                        </div>
                        <div class="bz-form__actions"><x-eh.button type="submit">Save details</x-eh.button></div>
                    </form>
                @else
                    <dl class="bz-facts">
                        <div><dt>Billing email</dt><dd>{{ $account->billing_email }}</dd></div>
                        <div><dt>VAT number</dt><dd>{{ $account->vat_number ?: 'None' }}</dd></div>
                        <div><dt>Address</dt><dd>{{ $account->address_line1 }}, {{ $account->city }}</dd></div>
                    </dl>
                @endif
            </section>

            <section class="bz-section">
                <div class="bz-section__label"><h2>Paying Dot.Ehail</h2><p>Statements are paid by EFT within the terms, or charged to a company card through Paystack when each statement is issued.</p>
                    <dl class="bz-facts" style="margin-top:var(--eh-space-4)">
                        <div><dt>Credit limit</dt><dd><x-eh.money :minor="(int) $account->credit_limit_minor" :currency="$cur" /></dd></div>
                        <div><dt>Owed now</dt><dd><x-eh.money :minor="$outstanding" :currency="$cur" /></dd></div>
                        <div><dt>Available for new rides</dt><dd><x-eh.money :minor="max(0, $creditAvailable)" :currency="$cur" /></dd></div>
                        <div><dt>Terms</dt><dd>{{ $account->terms_days }} days, then {{ $account->grace_days }} days' grace</dd></div>
                    </dl>
                </div>
                <div class="bz-form">
                    @if ($can['manage_billing'])
                        <form class="bz-form" method="POST" action="{{ route('business.settings.billing') }}">
                            @csrf @method('PUT')
                            <fieldset class="bz-checks" style="display:grid;gap:var(--eh-space-2)">
                                <legend>How the company pays</legend>
                                <label class="bz-option"><input type="radio" name="billing_mode" value="invoice" @checked($account->billing_mode === 'invoice')><span><strong>Monthly invoice</strong><small>Pay by EFT within {{ $account->terms_days }} days of each statement</small></span><span></span></label>
                                <label class="bz-option" @if (! $cardPayments) aria-disabled="true" @endif><input type="radio" name="billing_mode" value="card" @checked($account->billing_mode === 'card') @disabled(! $cardPayments)><span><strong>Company card</strong><small>{{ $cardPayments ? 'Charged when each statement is issued' : 'Card payments are not enabled on this environment' }}</small></span><span></span></label>
                            </fieldset>
                            @if ($cardPayments)
                                @if ($cards->isEmpty())
                                    <p class="bz-hint">To pay by card, first save the company card in your own wallet (it is charged R1.00 and refunded at once), then choose it here. @if (Route::has('ride.account'))<a class="eh-link" href="{{ route('ride.account') }}">Open your wallet</a>@endif</p>
                                @else
                                    <x-eh.select label="Company card" name="card_payment_method_id" :options="$cards->mapWithKeys(fn ($c) => [$c->id => $c->maskedLabel().' (expires '.$c->exp_month.'/'.$c->exp_year.')'])->all()" :value="$account->card_payment_method_id" placeholder="Choose a card" />
                                @endif
                            @endif
                            <div class="bz-form__actions"><x-eh.button type="submit">Save how we pay</x-eh.button>
                                @if ($account->cardMethod)<span class="bz-mark"><x-eh.icon name="card" />Charging {{ $account->cardMethod->maskedLabel() }}</span>@endif
                            </div>
                        </form>
                    @else
                        <p class="bz-hint">Pays by {{ $account->billing_mode === 'card' ? 'company card' : 'EFT' }}.</p>
                    @endif
                    @if ($account->billing_mode === 'card' && $account->status_note && $account->status === 'active')<x-eh.banner tone="caution">{{ $account->status_note }}</x-eh.banner>@endif
                </div>
            </section>
        </div>
    </div>
</x-business-layout>
