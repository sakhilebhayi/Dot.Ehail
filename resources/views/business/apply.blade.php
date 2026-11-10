<x-business-layout title="Business account">
    <x-slot:head>@include('business.partials.head')</x-slot:head>
    <div class="bz">
        <div class="bz-title">
            <div class="bz-title__text">
                <h1 class="eh-h1" style="margin:0">Business accounts open here</h1>
                <p class="bz-title__sub">Your company rides on account: people you invite, ride policies the app enforces before a ride is requested, trips billed to the company with their purpose and cost centre, and one statement a month.</p>
            </div>
        </div>

        @foreach ($invitations as $invitation)
            <x-eh.banner tone="info" title="{{ $invitation->account->displayName() }} invited you">
                Join as {{ strtolower($invitation->roleLabel()) }}. The invitation expires on {{ $invitation->invite_expires_at->format('j F Y') }}.
                <x-slot:actions>
                    <form method="POST" action="{{ route('business.invitations.accept-listed', $invitation->id) }}" style="margin:0">@csrf<x-eh.button type="submit" size="sm">Accept and join</x-eh.button></form>
                </x-slot:actions>
            </x-eh.banner>
        @endforeach

        <form class="bz-panel" method="POST" action="{{ route('business.apply') }}" novalidate>
            @csrf
            @error('business')<x-eh.banner tone="stop" style="margin-bottom:var(--eh-space-5)">{{ $message }}</x-eh.banner>@enderror
            <section class="bz-section">
                <div class="bz-section__label"><h2>The company</h2><p>As it appears on the company registration certificate. Dot.Ehail checks these before the account opens.</p></div>
                <div class="bz-form">
                    <div class="bz-form__row">
                        <x-eh.field label="Registered name" name="legal_name" required autocomplete="organization" />
                        <x-eh.field label="Trading name" name="trading_name" optional />
                    </div>
                    <div class="bz-form__row">
                        <x-eh.field label="Registration number" name="registration_number" required hint="For example 2019/123456/07" />
                        <x-eh.field label="VAT number" name="vat_number" optional hint="Shown on your statements when given" inputmode="numeric" />
                    </div>
                    @if ($teams->isNotEmpty())
                        <x-eh.select label="Link to your InfoDot company" name="team_id" :options="$teams->mapWithKeys(fn ($t) => [$t->id => $t->name.($t->infodot_team_uuid ? '' : ' (not from InfoDot)')])->all()" placeholder="Do not link" hint="Only companies you own or administer are listed." />
                    @endif
                </div>
            </section>
            <section class="bz-section">
                <div class="bz-section__label"><h2>Billing</h2><p>Where statements go and how the company pays. Credit limit and payment terms are set by Dot.Ehail when the account is approved.</p></div>
                <div class="bz-form">
                    <x-eh.field label="Billing email" name="billing_email" type="email" required autocomplete="email" />
                    <x-eh.field label="Street address" name="address_line1" required autocomplete="address-line1" />
                    <x-eh.field label="Address line 2" name="address_line2" optional autocomplete="address-line2" />
                    <div class="bz-form__row bz-form__row--3">
                        <x-eh.field label="City" name="city" required autocomplete="address-level2" />
                        <x-eh.field label="Postal code" name="postal_code" optional autocomplete="postal-code" />
                        <x-eh.select label="Country" name="country" :options="collect($countries)->map(fn ($c) => $c['name'])->all()" value="ZA" />
                    </div>
                    <x-eh.segmented legend="How the company pays" name="billing_mode" :options="['invoice' => 'Monthly invoice, pay by EFT', 'card' => 'Company card, charged monthly']" value="invoice" />
                    <div class="bz-form__actions">
                        <x-eh.button type="submit" icon="briefcase">Apply for a business account</x-eh.button>
                        <p class="bz-hint">You become the account admin. Nothing is charged until rides are billed.</p>
                    </div>
                </div>
            </section>
        </form>
    </div>
</x-business-layout>
