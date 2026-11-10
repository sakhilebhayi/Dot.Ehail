<x-business-layout title="Book a ride" :scope="$account->displayName()">
    <x-slot:head>@include('business.partials.head')@vite(['resources/js/business/book.js'])</x-slot:head>
    @php
        $cur = $account->currency;
        $urls = ['search' => route('geo.search'), 'quote' => route('pricing.quotes.store'), 'prepare' => route('business.api.prepare'), 'approval' => route('business.api.approvals.store'), 'ride' => route('ehail.dispatch.rides.store'), 'after' => Route::has('ride.home') ? route('ride.home') : route('business.trips')];
    @endphp
    <div class="bz">
        <div class="bz-title"><div class="bz-title__text">
            <h1 class="eh-h1" style="margin:0">Book a ride on the account</h1>
            <p class="bz-title__sub">{{ $profile['can_book_for_others'] ? 'For yourself, someone on the account or a guest.' : 'For yourself'.($profile['can_book_for_guests'] ? ' or a guest.' : '.') }} The fare and the policy check come before you request; the ride is billed to {{ $account->displayName() }} after the trip.</p>
        </div></div>
        @include('business.partials.state')

        <form class="bz-book" id="bz-book" data-profile="{{ $profile['id'] }}" novalidate
              data-urls="{{ json_encode($urls) }}">
            <div class="bz">
                <section class="bz-panel" aria-labelledby="bk-who">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="bk-who">Who is riding</h2></div>
                    <div class="bz-form">
                        <fieldset class="bz-checks" style="display:grid;gap:var(--eh-space-2)">
                            <legend class="eh-sr-only">Rider</legend>
                            <label class="bz-option"><input type="radio" name="rider" value="self" checked><span><strong>Me</strong><small>{{ auth()->user()->name }}</small></span><span></span></label>
                            @if ($profile['can_book_for_others'] && count($profile['travellers']) > 1)
                                <label class="bz-option"><input type="radio" name="rider" value="member"><span><strong>Someone on the account</strong><small>Counts against their allowance and policy</small></span><span></span></label>
                            @endif
                            @if ($profile['can_book_for_guests'])
                                <label class="bz-option"><input type="radio" name="rider" value="guest"><span><strong>A guest</strong><small>A visitor; the driver gets their name and phone</small></span><span></span></label>
                            @endif
                        </fieldset>
                        <div data-when="member" hidden>
                            <x-eh.select label="Traveller" name="traveller_member_id" :options="collect($profile['travellers'])->reject(fn ($t) => $t['member_id'] === $member->id)->mapWithKeys(fn ($t) => [$t['member_id'] => $t['name'].($t['department'] ? ' ('.$t['department'].')' : '')])->all()" placeholder="Choose a person" />
                            <x-eh.field label="Their phone, for the driver" name="rider_phone" type="tel" optional autocomplete="off" />
                        </div>
                        <div data-when="guest" hidden class="bz-form__row">
                            <x-eh.field label="Guest name" name="guest_name" autocomplete="off" />
                            <x-eh.field label="Guest phone" name="guest_phone" type="tel" autocomplete="off" hint="Shown to the driver only" />
                        </div>
                    </div>
                </section>

                <section class="bz-panel" aria-labelledby="bk-where">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="bk-where">Where</h2></div>
                    <div class="bz-places">
                        <div class="bz-place" data-place="pickup">
                            <x-eh.field label="Pickup" name="pickup_query" type="search" autocomplete="off" hint="Type at least three letters, then choose a place" />
                            <ul class="bz-suggest" role="listbox" aria-label="Pickup suggestions" hidden></ul>
                        </div>
                        <div class="bz-place" data-place="dropoff">
                            <x-eh.field label="Drop-off" name="dropoff_query" type="search" autocomplete="off" />
                            <ul class="bz-suggest" role="listbox" aria-label="Drop-off suggestions" hidden></ul>
                        </div>
                        <div class="bz-form__row">
                            <x-eh.field label="Purpose" name="purpose" :optional="! $profile['needs_purpose']" hint="{{ $profile['needs_purpose'] ? 'Your policy asks for one' : 'Printed on the statement' }}" maxlength="200" />
                            <x-eh.field label="Pickup time" name="scheduled_for" type="datetime-local" optional hint="Empty means now; 30 minutes to 30 days ahead" />
                        </div>
                    </div>
                </section>
            </div>

            <div class="bz">
                <section class="bz-panel" aria-labelledby="bk-fare" aria-live="polite">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="bk-fare">Fare and policy</h2></div>
                    <p class="bz-status" data-status>Choose a pickup and a drop-off to see the fares.</p>
                    <ul class="bz-options" data-options></ul>
                    <div data-check hidden style="margin-top:var(--eh-space-4)"></div>
                    <div class="bz-form__actions" style="margin-top:var(--eh-space-5)">
                        <x-eh.button type="submit" icon="car" data-request disabled>Request ride on the account</x-eh.button>
                        <x-eh.button type="button" variant="secondary" data-ask hidden>Ask for approval</x-eh.button>
                    </div>
                </section>
                <section class="bz-panel bz-panel--quiet" aria-labelledby="bk-limits">
                    <div class="bz-panel__head"><h2 class="bz-panel__title" id="bk-limits">Your limits</h2></div>
                    <dl class="bz-facts">
                        <div><dt>Allowance left this month</dt><dd>@if ($profile['allowance'])<x-eh.money :minor="$profile['allowance']['left_minor']" :currency="$cur" />@else No cap @endif</dd></div>
                        <div><dt>Approval needed above</dt><dd>@if ($profile['approval_above_minor'])<x-eh.money :minor="$profile['approval_above_minor']" :currency="$cur" />@else Never @endif</dd></div>
                    </dl>
                    <p class="bz-hint" style="margin-top:var(--eh-space-4)">You are the passenger of record: you see the trip PIN and the live trip in the passenger app, and can share the trip with the rider.</p>
                </section>
            </div>
        </form>
    </div>
</x-business-layout>
