{{-- Payouts: balances, the bank account they go to (with its verification state) and every payout's composition. --}}
@php
    $statusWord = ['scheduled' => 'Scheduled', 'processing' => 'Being sent', 'paid' => 'Paid', 'failed' => 'Failed', 'reversed' => 'Reversed'];
    $statusTone = ['scheduled' => 'neutral', 'processing' => 'info', 'paid' => 'go', 'failed' => 'stop', 'reversed' => 'caution'];
    $verifyWord = ['verified' => ['Verified by your bank', 'go'], 'unverified' => ['Not verified yet', 'neutral'], 'failed' => ['Bank did not confirm', 'stop'], 'unavailable' => ['Checked by operations', 'info']];
@endphp
<x-driver-layout title="Payouts">
    <x-slot:head>
        @vite(['resources/css/driver.css', 'resources/js/driver/pages.js'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>Payouts</h1>
            <p>Next batch is drafted {{ $nextPayout->isoFormat('dddd D MMMM [at] HH:mm') }}. {{ $transfers ? 'Operations sends it by bank transfer.' : 'Operations pays it from the bank and marks it paid.' }} Below <x-eh.money :minor="$minimumMinor" whole /> it carries over to the next week.</p>
        </div>
    </x-slot:header>

    <div class="dr-page">
        <section class="dr-panel" aria-labelledby="bal-h">
            <h2 id="bal-h">Balance</h2>
            <dl class="dr-ledger">
                <div><dt>Available for the next payout</dt><dd><x-eh.money :minor="$balances['available_minor']" /></dd></div>
                <div><dt>Pending (payment not settled yet)</dt><dd><x-eh.money :minor="$balances['pending_minor']" /></dd></div>
                <div><dt>Cash you hold for the platform</dt><dd><x-eh.money :minor="-$balances['cash_held_minor']" /></dd></div>
                <div class="is-total"><dt>Next payout, estimated</dt><dd><x-eh.money :minor="$balances['next_payout_estimate_minor']" /></dd></div>
            </dl>
            @if ($balances['cash_commission_owed_minor'] > 0)
                <p class="dr-note">You hold more cash than you have earned on card trips: <x-eh.money :minor="$balances['cash_commission_owed_minor']" /> is owed to the platform and is taken from future earnings.</p>
            @endif
            @if ($payee === 'fleet_owner')
                <p class="dr-note">Your operator pays fleet drivers through the fleet owner. Payouts below are the ones made to you directly, if any.</p>
            @endif
        </section>

        <section class="dr-section" aria-labelledby="bank-h">
            <h2 id="bank-h">Bank account</h2>
            @if ($account)
                @php([$vLabel, $vTone] = $verifyWord[$account->verification_status] ?? [$account->verification_status, 'neutral'])
                <div class="dr-panel">
                    <div class="dr-doc__head">
                        <p class="dr-doc__name">{{ $account->bank_name }} {{ $account->toPublicArray()['account_masked'] }}</p>
                        <x-eh.badge :tone="$vTone">{{ $vLabel }}</x-eh.badge>
                    </div>
                    @if ($account->verification_message)<p class="dr-note">{{ $account->verification_message }}</p>@endif
                    @if ($verificationAvailable && in_array($account->verification_status, ['unverified', 'failed'], true))
                        <form method="POST" action="{{ route('drive.earnings.bank-account.verify', $account) }}" class="dr-form" data-dr-typing>
                            @csrf
                            <p class="dr-note">Your bank can confirm the account belongs to you. Your ID number is sent to the check once and is not stored.</p>
                            <x-eh.select label="Document" name="document_type" :options="['identityNumber' => 'South African ID number', 'passportNumber' => 'Passport number']" value="identityNumber" />
                            <x-eh.field label="Number" name="document_number" size="driver" autocomplete="off" required />
                            <x-eh.button type="submit" size="driver">Check with my bank</x-eh.button>
                        </form>
                    @elseif (! $verificationAvailable && $account->verification_status === 'unverified')
                        <p class="dr-note">Automatic bank checks are not set up on this platform. Operations checks the account before paying it.</p>
                    @endif
                </div>
            @endif

            <details class="dr-more" @unless($account) open @endunless data-dr-typing>
                <summary>{{ $account ? 'Use a different account' : 'Add the account to be paid into' }}</summary>
                <form method="POST" action="{{ route('drive.earnings.bank-account') }}" class="dr-form dr-more__body">
                    @csrf
                    <x-eh.field label="Account holder" name="account_holder" size="driver" autocomplete="name" required />
                    <div class="dr-form__grid">
                        <x-eh.field label="Bank" name="bank_name" size="driver" hint="For example FNB, Capitec, Standard Bank." required />
                        <x-eh.field label="Branch code" name="bank_code" size="driver" inputmode="numeric" required />
                    </div>
                    <x-eh.field label="Account number" name="account_number" size="driver" inputmode="numeric" autocomplete="off" required />
                    <x-eh.select label="Account type" name="account_type" :options="['personal' => 'Personal', 'business' => 'Business']" value="personal" />
                    <p class="dr-note">The number is stored encrypted; only the last four digits are shown anywhere.</p>
                    <x-eh.button type="submit" size="driver">Save bank account</x-eh.button>
                </form>
            </details>
            <p class="dr-hold dr-alert dr-alert--caution">Bank details are paused while you drive.</p>
        </section>

        <section class="dr-section" aria-labelledby="hist-h">
            <h2 id="hist-h">Payout history</h2>
            @if ($payouts->isEmpty())
                <x-eh.empty title="No payouts yet" :pending="false" :heading-level="3">
                    <p style="margin:0">Each weekly batch you are part of appears here with exactly which earnings it pays and any cash netted.</p>
                </x-eh.empty>
            @else
                <ul class="eh-list">
                    @foreach ($payouts as $payout)
                        <li>
                            <a class="dr-trip-row" href="{{ route('drive.earnings.payout', $payout) }}">
                                <span class="dr-trip-row__title">Week to {{ $payout->batch?->period_end?->isoFormat('D MMMM') ?? 'date not set' }}</span>
                                <span class="dr-trip-row__meta">{{ (int) ($payout->composition['trips'] ?? 0) }} trips, earnings <x-eh.money :minor="$payout->earnings_minor" :currency="$payout->currency" />@if($payout->cash_offset_minor), cash netted <x-eh.money :minor="-$payout->cash_offset_minor" :currency="$payout->currency" />@endif</span>
                                <span class="dr-trip-row__amount"><x-eh.money :minor="$payout->amount_minor" :currency="$payout->currency" /><x-eh.badge :tone="$statusTone[$payout->status] ?? 'neutral'">{{ $statusWord[$payout->status] ?? $payout->status }}</x-eh.badge></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-driver-layout>
