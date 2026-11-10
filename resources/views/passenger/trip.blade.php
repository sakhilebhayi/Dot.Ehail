{{--
    One ride, live (/ride/trips/{ride}). The server paints the ride's state as it is now (works after a reload and
    without script); the feed client keeps it current. Panels: scheduled, searching, driver, PIN, waiting, trip,
    done (fare vs quote, payment, receipt, rating), ended. SOS sits in the top bar while the ride is active.
--}}
@php
    $r = $feed['ride'] ?? [];
    $status = $r['status'] ?? $ride->status;
    $position = $feed['driver_position'] ?? null;
    $text = \App\Domain\Passenger\RideText::for($r, $position);
    $stage = \App\Domain\Passenger\RideText::stageIndex($status);
    $active = in_array($status, ['requested', 'searching', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'], true);
    $holds = in_array($status, ['driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress'], true);
    $currency = $r['fare']['currency'] ?? 'ZAR';
    $stageLabels = ['Search', 'En route', 'Pickup', 'Trip', 'Arrived'];
@endphp
<x-passenger-layout :title="$text['title']" :bleed="true">
    <x-slot:head>
        @vite(['resources/css/passenger.css', 'resources/js/passenger/trip.js'])
    </x-slot:head>
    <x-slot:safety>
        <button type="button" class="eh-safety-btn eh-safety-btn--sos" data-pax-sos-open @if(! $active) hidden @endif><x-eh.icon name="sos" />SOS</button>
        <a class="eh-safety-btn" href="{{ route('ride.safety') }}" data-pax-safety-link @if($active) hidden @endif><x-eh.icon name="shield" />Safety</a>
    </x-slot:safety>

    <div class="pax-stage" data-pax-trip>
        <x-eh.map-frame id="pax-map" label="Map: your ride" :bleed="true" height="100%" message="The map loads after the ride details." aria-describedby="pax-map-summary">
            {{-- The map in words for screen readers (D-1704); the sheet also shows the stops and the arrival time. --}}
            <p class="eh-sr-only" id="pax-map-summary"></p>
        </x-eh.map-frame>

        <x-eh.sheet id="pax-ride" snap="half" class="pax-sheet">
            <p class="pax-net" data-pax-net hidden><x-eh.icon name="wifi-off" /><span data-pax-net-text>Connection lost. Showing the last update; we keep trying.</span></p>

            <header class="pax-state">
                <h1 class="pax-state__title" data-pax-title tabindex="-1">{{ $text['title'] }}</h1>
                <p class="pax-state__sub" data-pax-sub>{{ $text['sub'] }}</p>
            </header>

            {{-- Driver and car first: who to look for before anything else (jury item 4). --}}
            <section class="pax-who" data-panel="driver" @if(! $holds && $status !== 'completed') hidden @endif aria-label="Your driver">
                <p class="pax-who__ask" data-pax-who-ask @if(! in_array($status, ['driver_assigned', 'driver_arriving', 'driver_arrived'], true)) hidden @endif><x-eh.icon name="scan-face" />Check the car and plate</p>
                <div class="pax-driver">
                    <span class="eh-avatar pax-driver__face" data-pax-face aria-hidden="true">{{ mb_substr($r['driver']['first_name'] ?? '?', 0, 1) }}</span>
                    <div class="pax-driver__who">
                        <p class="pax-driver__name" data-pax-driver-name>{{ $r['driver']['first_name'] ?? 'Your driver' }}</p>
                        <p class="pax-driver__model" data-pax-car>{{ trim(($r['driver']['vehicle']['color'] ?? '').' '.($r['driver']['vehicle']['make'] ?? '').' '.($r['driver']['vehicle']['model'] ?? '')) }}</p>
                    </div>
                    <span class="pax-plate" data-pax-plate aria-label="Registration {{ $r['driver']['vehicle']['plate'] ?? '' }}">{{ $r['driver']['vehicle']['plate'] ?? '' }}</span>
                    <p class="pax-driver__facts"><span data-pax-driver-rating></span><span data-pax-driver-verified hidden><x-eh.icon name="shield-check" size="16" /> Documents verified</span></p>
                </div>
                <p class="pax-driver__check" data-pax-check>Check the registration and the driver's face before you get in. Never get into a car that does not match.</p>
                <p class="pax-state__sub pax-who__stale"><span class="pax-stale" data-pax-stale hidden><x-eh.icon name="clock" /><span data-pax-stale-text>Last position unknown</span></span></p>
            </section>

            {{-- Trip PIN --}}
            <section class="pax-pincode" data-panel="pin" @if(empty($r['pin'])) hidden @endif aria-labelledby="pax-pin-label">
                <p class="pax-pincode__label" id="pax-pin-label">Your trip PIN</p>
                <p class="pax-pincode__digits" data-pax-pin role="img" aria-label="Trip PIN {{ implode(' ', str_split((string) ($r['pin'] ?? ''))) }}">
                    @foreach (str_split((string) ($r['pin'] ?? '')) as $digit)<span class="pax-pincode__digit" aria-hidden="true">{{ $digit }}</span>@endforeach
                </p>
                <p class="pax-pincode__help">Say this PIN to the driver when you get in. The trip cannot start without it.</p>
            </section>

            {{-- Waiting at pickup --}}
            <section class="pax-wait" data-panel="wait" @if($status !== 'driver_arrived') hidden @endif aria-label="Waiting time">
                <span class="pax-rule">Driver waiting for</span>
                <span class="pax-wait__clock" data-pax-wait-clock>0:00</span>
                <p class="pax-rule" data-pax-wait-rule></p>
            </section>

            <ol class="eh-stepper eh-stepper--horizontal pax-steps" aria-label="Ride progress" data-pax-steps @if($stage < 0) hidden @endif>
                @foreach ($stageLabels as $i => $label)
                    @php $s = $i < $stage ? 'done' : ($i === $stage ? 'current' : 'todo'); @endphp
                    <li class="eh-stepper__step" data-state="{{ $s }}" @if($s === 'current') aria-current="step" @endif>
                        <span class="eh-stepper__dot">@if($s === 'done')<x-eh.icon name="check" />@endif</span>
                        <span class="eh-stepper__body"><span class="eh-stepper__label">{{ $label }}</span></span>
                    </li>
                @endforeach
            </ol>

            {{-- Scheduled ride --}}
            <section class="pax-section" data-panel="scheduled" @if($status !== 'scheduled') hidden @endif aria-label="Scheduled ride">
                <p style="margin:0" class="pax-trip">
                    <span class="pax-trip__figure" data-pax-scheduled-at>{{ isset($r['scheduled_for']) ? \Illuminate\Support\Carbon::parse($r['scheduled_for'])->timezone(config('app.timezone'))->format('D j M, H:i') : '' }}</span>
                    <span class="eh-badge {{ ($r['schedule_phase'] ?? '') === 'driver_confirmed' ? 'eh-badge--go' : 'eh-badge--caution' }}" data-pax-phase>{{ ($r['schedule_phase'] ?? '') === 'driver_confirmed' ? 'Driver confirmed' : 'Awaiting driver' }}</span>
                </p>
                <p class="pax-rule">We start finding your driver {{ $schedule['allocation_lead_minutes'] }} minutes before pickup. Your booked fare is kept if a fresh price then is within {{ $schedule['price_tolerance_percent'] }}%; if it is higher we ask you first. Change or cancel free of charge until {{ $schedule['free_change_until_minutes'] }} minutes before pickup.</p>
                <div class="eh-banner eh-banner--caution pax-section" role="alert" data-pax-requote hidden>
                    <x-eh.icon name="warning" />
                    <div class="eh-banner__body">
                        <p class="eh-banner__title">The price for this ride went up</p>
                        <div class="eh-banner__text">A fresh price for your route is <strong data-pax-requote-total></strong>, more than {{ $schedule['price_tolerance_percent'] }}% above what you booked. Accept it to keep the ride, or cancel without a fee.</div>
                        <div class="eh-banner__actions"><x-eh.button size="sm" data-pax-requote-accept>Accept the new price</x-eh.button></div>
                    </div>
                </div>
                <details class="pax-promo pax-section" data-pax-reschedule-box>
                    <summary class="eh-textlink"><x-eh.icon name="calendar-clock" />Change the pickup time</summary>
                    <form class="pax-form pax-later" data-pax-reschedule>
                        <x-eh.datetime id="pax-reschedule-at" label="New pickup time" />
                        <div><x-eh.button type="submit" variant="secondary">Change time</x-eh.button></div>
                        <p class="pax-field__status" data-pax-reschedule-status role="status"></p>
                    </form>
                </details>
            </section>

            {{-- Searching --}}
            <section class="pax-section" data-panel="searching" @if(! in_array($status, ['requested', 'searching'], true)) hidden @endif aria-label="Finding a driver">
                <div class="pax-progress" role="progressbar" aria-label="Search time used" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-pax-search-progress><div class="pax-progress__bar"></div></div>
                <p class="pax-rule" data-pax-search-left>We keep looking for up to {{ (int) config('ehail.ride_expiry_minutes', 10) }} minutes.</p>
            </section>

            {{-- In the trip --}}
            <section class="pax-section" data-panel="trip" @if($status !== 'in_progress') hidden @endif aria-label="Trip tools">
                <div class="pax-actions pax-actions--row">
                    <x-eh.button variant="secondary" icon="share" data-pax-share>Share trip</x-eh.button>
                    <x-eh.button variant="ghost" icon="shield" :href="route('ride.safety')">Safety centre</x-eh.button>
                </div>
                <details class="pax-promo pax-section" data-pax-change>
                    <summary class="eh-textlink"><x-eh.icon name="route" />Change the destination</summary>
                    <p class="pax-rule">The fare is worked out again for the new route under the same tariff, and the receipt says why it changed.</p>
                    <x-eh.place-field id="pax-change" label="New destination" placeholder="Street and suburb" dot="dropoff" data-pax-search="change" />
                    <x-eh.button size="sm" data-pax-change-confirm disabled>Go here instead</x-eh.button>
                    <p class="pax-field__status" data-pax-change-status role="status"></p>
                </details>
            </section>

            {{-- Share link (any active stage) --}}
            @include('passenger.partials.share-box', ['heading' => 'h2'])

            <ol class="pax-route pax-section" aria-label="Route">
                <li class="pax-route__stop"><span class="pax-route__dot" aria-hidden="true"></span><span class="pax-route__text"><span class="pax-route__label">Pickup</span><span class="pax-route__address" data-pax-pickup>{{ $r['pickup']['address'] ?? $ride->pickup_address }}</span></span></li>
                <li class="pax-route__stop pax-route__stop--drop"><span class="pax-route__dot" aria-hidden="true"></span><span class="pax-route__text"><span class="pax-route__label">Destination</span><span class="pax-route__address" data-pax-dropoff>{{ $r['dropoff']['address'] ?? $ride->dropoff_address }}</span></span></li>
            </ol>

            {{-- Price and payment while the ride is on --}}
            <p class="pax-trip" data-panel="price" @if(! $active && $status !== 'scheduled') hidden @endif>
                <span class="pax-trip__figure eh-num" data-pax-quoted>{{ isset($r['fare']['quoted_total_minor']) ? \App\Support\Ui\MoneyFormatter::format((int) $r['fare']['quoted_total_minor'], $currency) : '' }}</span>
                <span class="pax-trip__note" data-pax-paywith>{{ $overview['payment']['method_label'] ? 'Paying with '.$overview['payment']['method_label'] : 'Agreed price' }}</span>
            </p>

            {{-- Done: final fare, payment, receipt, rating --}}
            <section class="pax-section" data-panel="done" @if($status !== 'completed') hidden @endif aria-label="Trip summary">
                <dl class="pax-compare">
                    <div><dt>Quoted</dt><dd data-pax-done-quoted>{{ isset($r['fare']['quoted_total_minor']) ? \App\Support\Ui\MoneyFormatter::format((int) $r['fare']['quoted_total_minor'], $currency) : '' }}</dd></div>
                    <div><dt>You pay</dt><dd data-pax-done-final>{{ isset($r['fare']['final_total_minor']) ? \App\Support\Ui\MoneyFormatter::format((int) $r['fare']['final_total_minor'], $currency) : 'Being finalised' }}</dd></div>
                </dl>
                <ul class="pax-changes" data-pax-changes></ul>
                <details class="pax-promo"><summary class="eh-textlink">Final fare breakdown<x-eh.icon name="chevron-down" /></summary><dl class="pax-fare" data-pax-final-breakdown></dl></details>
                <div class="pax-section" data-pax-payment-state>
                    <p class="pax-rule"><span class="eh-badge" data-pax-pay-badge>{{ $overview['payment']['label'] }}</span> <span data-pax-pay-method>{{ $overview['payment']['method_label'] }}</span></p>
                    <div class="pax-actions pax-actions--row">
                        <x-eh.button variant="secondary" size="sm" icon="receipt" :href="$overview['receipt']['url'] ?? '#'" data-pax-receipt :hidden="! isset($overview['receipt'])">Receipt</x-eh.button>
                        <x-eh.button size="sm" icon="card" data-pax-pay-now hidden>Pay now</x-eh.button>
                    </div>
                </div>

                <form class="pax-section" data-pax-rate @if(! $overview['can_rate'] || $overview['rated']) hidden @endif>
                    <fieldset class="pax-stars">
                        <legend>Rate your trip with {{ $r['driver']['first_name'] ?? 'your driver' }}</legend>
                        <div class="pax-stars__row">
                            @for ($n = 5; $n >= 1; $n--)
                                <input type="radio" name="rating" id="pax-star-{{ $n }}" value="{{ $n }}" required>
                                <label for="pax-star-{{ $n }}" title="{{ $n }} {{ $n === 1 ? 'star' : 'stars' }}"><span class="eh-sr-only">{{ $n }} {{ $n === 1 ? 'star' : 'stars' }}</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.8l2.8 5.7 6.3.9-4.6 4.4 1.1 6.2L12 17.1 6.4 20l1.1-6.2L2.9 9.4l6.3-.9z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></label>
                            @endfor
                        </div>
                    </fieldset>
                    <div class="pax-tags" role="group" aria-label="What stood out" data-pax-tags>
                        @foreach (['Safe driving', 'Friendly', 'Clean car', 'Knew the way', 'Arrived on time'] as $tag)
                            <button type="button" class="eh-chip" aria-pressed="false" data-tag="{{ $tag }}">{{ $tag }}</button>
                        @endforeach
                    </div>
                    <div class="eh-field">
                        <label class="eh-field__label" for="pax-rate-comment">Comment <span class="eh-field__optional">optional</span></label>
                        <textarea class="eh-textarea" id="pax-rate-comment" name="comment" maxlength="400" rows="2"></textarea>
                        <p class="eh-field__hint">Your driver can read comments after they are checked. To raise something privately, use "Report a problem" below.</p>
                    </div>
                    <x-eh.button type="submit">Send rating</x-eh.button>
                    <p class="pax-field__status" data-pax-rate-status role="status"></p>
                </form>
                <p class="pax-rule" data-pax-rated @if(! $overview['rated']) hidden @endif><x-eh.icon name="check" size="16" /> Thanks, your rating is saved.</p>

                <details class="pax-promo pax-section" data-pax-report-box @if(! ($r['driver'] ?? null)) hidden @endif>
                    <summary class="eh-textlink"><x-eh.icon name="flag" />Report a problem with this trip</summary>
                    <form class="pax-form" data-pax-report>
                        <x-eh.select label="What happened?" name="category" :options="['unsafe_driving' => 'Unsafe driving', 'harassment' => 'Harassment', 'abusive_language' => 'Abusive language', 'discrimination' => 'Discrimination', 'intoxication' => 'Driver seemed impaired', 'vehicle_mismatch' => 'The car did not match', 'driver_mismatch' => 'The driver did not match', 'damage' => 'Damage', 'fraud' => 'Fraud or overcharging', 'threatening_behaviour' => 'Threatening behaviour', 'other' => 'Something else']" />
                        <x-eh.field label="Tell us more" name="description" type="textarea" :optional="true" />
                        <p class="pax-rule">Reports are private: the driver is not told who reported them. For a fare question or a lost item, <a class="eh-link" href="{{ route('ride.support.create', ['ride' => $ride->id]) }}">open a help case</a>.</p>
                        <x-eh.button type="submit" variant="secondary">Send report</x-eh.button>
                        <p class="pax-field__status" data-pax-report-status role="status"></p>
                    </form>
                </details>
            </section>

            {{-- Ended without a trip --}}
            <section class="pax-section" data-panel="ended" @if(! in_array($status, ['cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_operator', 'expired', 'no_show', 'failed', 'needs_review'], true)) hidden @endif aria-label="Ride ended">
                <p class="pax-trip" data-pax-fee @if(empty($r['fare']['cancellation_fee_minor'])) hidden @endif><span class="pax-trip__figure" data-pax-fee-amount>{{ ! empty($r['fare']['cancellation_fee_minor']) ? \App\Support\Ui\MoneyFormatter::format((int) $r['fare']['cancellation_fee_minor'], $currency) : '' }}</span><span class="pax-trip__note">Fee under the fare rules</span></p>
            </section>

            {{-- Actions --}}
            <div class="pax-actions" data-pax-actions>
                <div data-pax-cancel-box @if(! $active && $status !== 'scheduled') hidden @endif>
                    <p class="pax-rule" data-pax-cancel-rule></p>
                    <x-eh.button variant="ghost" :block="true" data-pax-cancel>Cancel ride</x-eh.button>
                </div>
                <x-eh.button variant="secondary" icon="share" data-pax-share-pre :hidden="! $holds || $status === 'in_progress'">Share trip</x-eh.button>
                <x-eh.button :href="route('ride.home', ['again' => $ride->id, 'new' => 1])" icon="route" data-pax-again :hidden="$active || $status === 'scheduled'">Book this route again</x-eh.button>
                <x-eh.button variant="ghost" icon="lifebuoy" :href="route('ride.support.create', ['ride' => $ride->id])">Get help with this ride</x-eh.button>
            </div>
        </x-eh.sheet>
    </div>

    {{-- Cancel: say what it costs before it happens --}}
    <x-eh.dialog id="pax-cancel-dialog" title="Cancel this ride?">
        <form class="pax-form" data-pax-cancel-form method="dialog">
            <p style="margin:0" data-pax-cancel-cost>Cancelling now is free.</p>
            <x-eh.select label="Why are you cancelling?" name="reason" :options="['changed_plans' => 'My plans changed', 'waited_too_long' => 'The wait is too long', 'wrong_address' => 'Wrong address entered', 'found_other_ride' => 'I found another ride', 'safety_concern' => 'Safety concern', 'other' => 'Something else']" />
            <p class="pax-field__status" data-kind="error" data-pax-cancel-error role="alert"></p>
            <div class="pax-actions pax-actions--row">
                <x-eh.button type="button" variant="danger" data-pax-cancel-confirm>Cancel the ride</x-eh.button>
                <x-eh.button type="button" variant="secondary" data-eh-dialog-close>Keep the ride</x-eh.button>
            </div>
        </form>
    </x-eh.dialog>

    @include('passenger.partials.sos', ['rideId' => $ride->id])
    @include('passenger.partials.check-in')

    <div id="pax-live" class="pax-live" aria-live="polite"></div>
    <div id="pax-live-assertive" class="pax-live" aria-live="assertive"></div>

    <script type="application/json" data-geo-config>@json($geoConfig)</script>
    @php $paxIsland = [
        'rideId' => $ride->id,
        'feed' => $feed,
        'overview' => $overview,
        'schedule' => $schedule,
        'urls' => [
            'feed' => route('ehail.dispatch.rides.feed', $ride),
            'overview' => route('ride.api.rides.overview', $ride),
            'cancel' => route('ehail.dispatch.rides.cancel', $ride),
            'reschedule' => route('ehail.dispatch.rides.reschedule', $ride),
            'requote' => route('ehail.dispatch.rides.requote.accept', $ride),
            'tripChange' => route('ehail.dispatch.rides.trip-change', $ride),
            'fare' => route('pricing.rides.fare', $ride),
            'safety' => route('safety.rides.show', $ride),
            'shares' => route('safety.rides.shares.store', $ride),
            'shareRevoke' => route('safety.shares.revoke', ['share' => '__ID__']),
            'rating' => route('safety.rides.rating', $ride),
            'report' => route('safety.rides.report', $ride),
            'payNow' => route('money.rides.pay-now', $ride),
            'wallet' => route('money.wallet'),
            'driverLocation' => route('geo.rides.driver-location', $ride),
            'route' => route('geo.route'),
        ],
    ]; @endphp
    <script type="application/json" id="pax-trip-data">@json($paxIsland)</script>
</x-passenger-layout>
