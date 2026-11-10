{{--
    Passenger booking (/ride): map behind, sheet in front. Steps: where (pickup, destination, now or later),
    pin (move the map under the pin to set the pickup exactly), options (compare categories with the full fare,
    promo, payment, request). The sheet renders without script; the map loads after it.
--}}
<x-passenger-layout title="Ride" :bleed="true">
    <x-slot:head>
        @vite(['resources/css/passenger.css', 'resources/js/passenger/booking.js'])
    </x-slot:head>

    <h1 class="eh-sr-only">Book a ride</h1>
    <div class="pax-stage" data-pax-booking>
        <x-eh.map-frame id="pax-map" label="Map: your pickup and destination" :bleed="true" height="100%" message="The map loads after the booking panel. You can book without it.">
            <div class="pax-pin" data-pax-pin hidden aria-hidden="true">
                <svg viewBox="0 0 40 52" aria-hidden="true"><path d="M20 2C10.6 2 3 9.4 3 18.6 3 31 20 50 20 50s17-19 17-31.4C37 9.4 29.4 2 20 2z" fill="currentColor" class="pax-pin__ring" stroke-width="2.5"/><circle cx="20" cy="18.5" r="6" fill="#0f1f19"/></svg>
            </div>
        </x-eh.map-frame>

        <x-eh.sheet id="pax-book" snap="half" class="pax-sheet">
            <noscript>
                <x-eh.banner tone="caution" title="Booking needs JavaScript">
                    Turn on JavaScript to search places and compare fares. You can still use the
                    <a class="eh-link" href="{{ route('rides.create') }}">simple request form</a>.
                </x-eh.banner>
            </noscript>

            @if ($paymentDue['blocked'] ?? false)
                <x-eh.banner tone="stop" title="A fare is still unpaid" class="pax-due" data-pax-due-static>
                    {{ $paymentDue['reason'] ?? 'Pay the outstanding fare before you request another ride.' }}
                    <x-slot:actions>
                        <x-eh.button size="sm" variant="secondary" :href="route('ride.trips')">See the unpaid ride</x-eh.button>
                    </x-slot:actions>
                </x-eh.banner>
            @endif

            {{-- Step 1: where to --}}
            <section data-step="where" aria-labelledby="pax-where-title">
                <h2 class="eh-sheet__title" id="pax-where-title" tabindex="-1" style="margin-bottom: var(--eh-space-3)">Where to?</h2>
                <p class="pax-net" data-pax-net hidden><x-eh.icon name="wifi-off" /><span>You are offline. Your choices are kept; prices load when you are back.</span></p>

                <div class="pax-field" data-pax-search="pickup">
                    <label class="pax-field__label" for="pax-pickup"><span class="pax-route__dot" aria-hidden="true"></span>Pickup</label>
                    <div class="pax-field__row">
                        <input id="pax-pickup" class="eh-input" type="search" placeholder="Street and suburb" enterkeyhint="search">
                        <x-eh.button variant="secondary" size="md" data-geo-search-submit icon="search">Search</x-eh.button>
                    </div>
                    <p class="pax-field__hint" data-geo-search-hint></p>
                    <ul class="pax-results" data-geo-search-list></ul>
                    <p class="pax-field__status" data-geo-search-status></p>
                    <div class="pax-field__tools">
                        <x-eh.button variant="ghost" size="sm" icon="locate" data-pax-locate>Use my location</x-eh.button>
                        <x-eh.button variant="ghost" size="sm" icon="pin" data-pax-adjust disabled>Move the pin</x-eh.button>
                    </div>
                    <p class="pax-field__status" data-pax-locate-status role="status" aria-live="polite"></p>
                </div>

                <div class="pax-field" data-pax-search="dropoff">
                    <label class="pax-field__label" for="pax-dropoff"><span class="pax-route__dot pax-route__dot--drop" aria-hidden="true"></span>Destination</label>
                    <div class="pax-field__row">
                        <input id="pax-dropoff" class="eh-input" type="search" placeholder="Where are you going?" enterkeyhint="search">
                        <x-eh.button variant="secondary" size="md" data-geo-search-submit icon="search">Search</x-eh.button>
                    </div>
                    <p class="pax-field__hint" data-geo-search-hint></p>
                    <ul class="pax-results" data-geo-search-list></ul>
                    <p class="pax-field__status" data-geo-search-status></p>
                </div>

                <div class="pax-places" data-pax-places role="group" aria-label="Saved places" @if($places->isEmpty()) hidden @endif>
                    @foreach ($places as $place)
                        <button type="button" class="eh-chip" data-pax-place="{{ $place['id'] }}"><x-eh.icon :name="$place['kind'] === 'work' ? 'briefcase' : ($place['kind'] === 'home' ? 'home' : 'pin')" />{{ $place['label'] }}</button>
                    @endforeach
                </div>
                <p class="pax-attribution" data-pax-geo-attribution></p>

                <div class="pax-when" data-pax-when>
                    <x-eh.segmented legend="When do you need the ride?" name="when" :options="['now' => 'Now', 'later' => ['Later', 'calendar-clock']]" value="now" id-prefix="pax-when" />
                    <div class="pax-when__later" data-pax-later hidden>
                        <label class="eh-field__label" for="pax-later-at">Pickup date and time</label>
                        <input id="pax-later-at" class="eh-input" type="datetime-local" step="300">
                        <p class="pax-rule">Book {{ $schedule['min_lead_minutes'] }} minutes to {{ $schedule['max_lead_days'] }} days ahead. We start finding your driver {{ $schedule['allocation_lead_minutes'] }} minutes before pickup; until then the ride shows "awaiting driver". Your booked fare is kept if a fresh price at that time is within {{ $schedule['price_tolerance_percent'] }}%; if it is higher, we ask you before we dispatch.</p>
                    </div>
                </div>

                <div class="pax-actions">
                    <x-eh.button size="lg" :block="true" icon-right="arrow-right" data-pax-see-prices disabled>See prices</x-eh.button>
                    <p class="pax-rule" data-pax-where-hint>Set a pickup and a destination to see every ride type with its full fare.</p>
                </div>
            </section>

            {{-- Step 2: confirm the pickup pin --}}
            <section data-step="pin" aria-labelledby="pax-pin-title" hidden>
                <h2 class="eh-sheet__title" id="pax-pin-title" tabindex="-1">Confirm your pickup</h2>
                <p class="pax-lead" style="margin-top: var(--eh-space-2)">Move the map so the pin sits where the driver should stop: a gate, a corner, a shop front.</p>
                <ol class="pax-route">
                    <li class="pax-route__stop"><span class="pax-route__dot" aria-hidden="true"></span><span class="pax-route__text"><span class="pax-route__label">Pickup</span><span class="pax-route__address" data-pax-pin-address role="status" aria-live="polite">Finding the address</span></span></li>
                </ol>
                <div class="pax-actions">
                    <x-eh.button size="lg" :block="true" icon="check" data-pax-pin-confirm>Confirm pickup</x-eh.button>
                    <x-eh.button variant="ghost" :block="true" data-pax-pin-cancel>Back</x-eh.button>
                </div>
            </section>

            {{-- Step 3: compare, pay, request --}}
            <section data-step="options" aria-labelledby="pax-options-title" hidden>
                <x-eh.button variant="ghost" size="sm" icon="arrow-left" class="pax-back" data-pax-back>Change route</x-eh.button>
                <h2 class="eh-sheet__title" id="pax-options-title" tabindex="-1">Choose a ride</h2>
                <ol class="pax-route" style="margin-top: var(--eh-space-3)">
                    <li class="pax-route__stop"><span class="pax-route__dot" aria-hidden="true"></span><span class="pax-route__text"><span class="pax-route__label">Pickup</span><span class="pax-route__address" data-pax-sum="pickup"></span></span></li>
                    <li class="pax-route__stop pax-route__stop--drop"><span class="pax-route__dot" aria-hidden="true"></span><span class="pax-route__text"><span class="pax-route__label">Destination</span><span class="pax-route__address" data-pax-sum="dropoff"></span></span></li>
                </ol>
                <p class="pax-net" data-pax-net hidden><x-eh.icon name="wifi-off" /><span>You are offline. Prices refresh when you are back.</span></p>
                <div data-pax-options-body aria-live="polite" aria-busy="true">
                    <x-eh.skeleton :lines="4" />
                </div>
            </section>
        </x-eh.sheet>
    </div>

    <div id="pax-live" class="pax-live" aria-live="polite"></div>
    <div id="pax-live-assertive" class="pax-live" aria-live="assertive"></div>

    {{-- Icons the script clones into the cards it draws (one set, same stroke as the rest of the kit). --}}
    <template id="pax-icons">
        @foreach (['car', 'car-sedan', 'car-xl', 'car-hatch', 'accessibility', 'luggage', 'users', 'clock', 'chevron-down', 'card', 'cash', 'briefcase', 'plus', 'info', 'warning', 'route', 'refresh', 'tag'] as $iconName)
            <span data-icon="{{ $iconName }}"><x-eh.icon :name="$iconName" /></span>
        @endforeach
    </template>

    <script type="application/json" data-geo-config>@json($geoConfig)</script>
    @php $paxIsland = [
        'places' => $places,
        'again' => $again,
        'schedule' => $schedule,
        'paymentDue' => $paymentDue,
        'urls' => [
            'quotes' => route('pricing.quotes.store'),
            'request' => route('ehail.dispatch.rides.store'),
            'trip' => route('ride.trip', ['ride' => '__ID__']),
            'wallet' => route('money.wallet'),
            'addCard' => route('money.cards.start'),
            'payNow' => route('money.rides.pay-now', ['ride' => '__ID__']),
            'places' => route('ride.api.places.store'),
            'etas' => route('ride.api.pickup-etas'),
            'route' => route('geo.route'),
            'reverse' => route('geo.reverse'),
            'trips' => route('ride.trips'),
            'profiles' => Route::has('business.api.profiles') ? route('business.api.profiles') : null,
            'businessPrepare' => Route::has('business.api.prepare') ? route('business.api.prepare') : null,
            'businessApprovals' => Route::has('business.api.approvals.store') ? route('business.api.approvals.store') : null,
        ],
    ]; @endphp
    <script type="application/json" id="pax-booking-data">@json($paxIsland)</script>
</x-passenger-layout>
