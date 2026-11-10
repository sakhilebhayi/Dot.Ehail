{{-- Driver account: vehicle, documents, payouts, cash trips, this phone's app settings, profile. --}}
@php($profile = $state->profile())
<x-driver-layout title="Account">
    <x-slot:head>
        @vite(['resources/css/driver.css', 'resources/js/driver/pages.js'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>Account</h1>
            <p>{{ auth()->user()->name }}@if($profile), driver profile {{ $profile->status }}@endif.</p>
        </div>
    </x-slot:header>

    <div class="dr-page">
        @if ($profile)
            <ul class="eh-list">
                <x-eh.list-row title="Vehicles" :meta="$state->activeVehicle() ? 'Active: '.$state->activeVehicle()->plate_number : 'No active vehicle'" icon="car" :href="route('drivers.vehicles.index')" />
                <x-eh.list-row title="Documents" :meta="$documents['counts']['done'].' of '.$documents['counts']['total'].' approved'" icon="document-check" :href="route('drive.documents')" />
                <x-eh.list-row title="Payouts and bank account" icon="wallet" :href="route('drive.earnings.payouts')" />
                <x-eh.list-row title="Help and safety" meta="Support cases, ratings, emergency numbers" icon="lifebuoy" :href="route('drive.safety')" />
                <x-eh.list-row title="Onboarding checklist" icon="list" :href="route('drive.onboarding')" />
            </ul>
        @else
            <x-eh.button size="driver" :href="route('drive.apply')" icon="steering-wheel" block>Apply to drive</x-eh.button>
        @endif

        <section class="dr-section" aria-labelledby="phone-h">
            <h2 id="phone-h">On this phone</h2>
            <p>These settings are saved in this browser, not on your account.</p>
            <form class="dr-form dr-panel" data-dr-prefs>
                <fieldset class="dr-fieldset">
                    <legend class="eh-field__label">Open navigation in</legend>
                    @foreach (['google' => 'Google Maps', 'waze' => 'Waze', 'apple' => 'Apple Maps'] as $value => $label)
                        <label class="dr-reason"><input type="radio" name="nav" value="{{ $value }}" @checked($value === 'google')> {{ $label }}</label>
                    @endforeach
                </fieldset>
            </form>
            @include('driver._offer-alerts', ['uid' => 'dr-account-alerts', 'class' => 'dr-panel', 'headingLevel' => 3])
        </section>

        @if ($profile)
            <section class="dr-section" aria-labelledby="cash-h">
                <h2 id="cash-h">Cash trips</h2>
                @if ($cashOnPlatform)
                    <form class="dr-panel dr-form" method="POST" action="{{ route('drive.account.cash') }}">
                        @csrf
                        <input type="hidden" name="accepts_cash" value="{{ $acceptsCash ? 0 : 1 }}">
                        <p style="margin:0">You {{ $acceptsCash ? 'accept' : 'do not accept' }} cash trips. Cash you collect is netted against your payouts; carrying cash has a safety cost, so it is your choice.</p>
                        <x-eh.button type="submit" size="driver" :variant="$acceptsCash ? 'secondary' : 'primary'">{{ $acceptsCash ? 'Stop accepting cash' : 'Accept cash trips' }}</x-eh.button>
                    </form>
                @else
                    <p class="dr-note">Cash trips are switched off on this platform. Passengers pay by card or business account.</p>
                @endif
            </section>
        @endif

        <ul class="eh-list">
            @if (Route::has('drive.notifications'))
                <x-eh.list-row title="Notifications" meta="Your inbox: offers, trips, documents and payouts" icon="bell" :href="route('drive.notifications')" />
            @endif
            @if (Route::has('drive.notifications.settings'))
                <x-eh.list-row title="Notification settings" meta="Push, email and SMS, and what each one reached" icon="settings" :href="route('drive.notifications.settings')" />
            @endif
            <x-eh.list-row title="Profile and password" icon="account" :href="route('profile.show')" />
            @if (Route::has('account.data-export'))
                <x-eh.list-row title="Download my data" meta="A copy of what Dot.Ehail holds about you" icon="download" :href="route('account.data-export')" />
            @endif
        </ul>
    </div>
</x-driver-layout>
