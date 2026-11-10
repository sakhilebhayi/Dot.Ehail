{{--
    The driver's working screen (/drive) for an approved driver. One question: "what do I do next?".
    Server-rendered first state (from the dispatch feed), then the console script polls the feed and drives the
    views: offline, online (waiting for offers), the offer card, the trip and the trip summary.
--}}
@php
    $availability = $feed['availability']['state'] ?? 'offline';
    $hasRide = ($feed['ride'] ?? null) !== null;
    $initial = $hasRide ? 'trip' : (in_array($availability, ['online', 'paused'], true) ? 'online' : 'offline');
    $statebar = match (true) {
        $hasRide => ['busy', 'On a trip', $feed['ride']['status_label'] ?? 'Trip in progress', 'route'],
        $availability === 'online' => ['online', 'You are online', 'Waiting for ride offers. Each offer shows the pickup, the trip and your earnings before you answer.', 'online'],
        $availability === 'paused' => ['offline', 'Paused', 'You stay signed in but get no offers until you resume.', 'offline'],
        default => ['offline', 'You are offline', 'Go online when you are ready to take rides.', 'offline'],
    };
    $vehicle = $state->activeVehicle();
    $eligible = $eligibility['allowed'];
    $todayTrips = (int) ($today['trips'] ?? 0);
@endphp
<x-driver-layout title="Drive">
    <x-slot:head>
        @vite(['resources/css/driver.css', 'resources/js/driver/drive.js'])
    </x-slot:head>

    <x-slot:safety>
        <button type="button" class="dr-sos-btn" data-dr-sos-open hidden><x-eh.icon name="sos" />SOS</button>
        <a class="eh-safety-btn" href="{{ route('drive.safety') }}" data-dr-safety-link><x-eh.icon name="shield" />Safety</a>
    </x-slot:safety>

    <x-slot:state>
        <section class="eh-statebar" data-state="{{ $statebar[0] }}" aria-labelledby="drive-state-title" data-dr-statebar>
            @foreach (['offline', 'online', 'route'] as $mark)
                <span data-dr-mark="{{ $mark }}" class="eh-statebar__mark" @if($mark !== $statebar[3]) hidden @endif><x-eh.icon :name="$mark" /></span>
            @endforeach
            <div class="eh-statebar__text">
                <h1 class="eh-statebar__title" id="drive-state-title" data-dr-state-title>{{ $statebar[1] }}</h1>
                <p class="eh-statebar__sub" data-dr-state-sub aria-live="polite">{{ $statebar[2] }}</p>
            </div>
        </section>
    </x-slot:state>

    <script type="application/json" id="dr-boot">@json($boot)</script>

    <noscript>
        <x-eh.banner tone="caution" title="The driver app needs JavaScript">Offers, location and trip steps run in the page. Switch JavaScript on in your browser to drive.</x-eh.banner>
    </noscript>

    <div class="dr-console" data-dr-console data-view="{{ $initial }}">
        {{-- Connection and phone warnings: shown by the script, one at a time, most serious first. --}}
        <div class="dr-alerts" data-dr-alerts aria-live="assertive"></div>

        {{-- OFFLINE --}}
        <section class="dr-view" data-dr-view="offline" @if($initial !== 'offline') hidden @endif aria-labelledby="dr-offline-h">
            <h2 class="eh-sr-only" id="dr-offline-h">Go online</h2>

            <div class="dr-vehicle">
                <x-eh.icon name="car" />
                <div class="dr-vehicle__text">
                    @if ($vehicle)
                        <span class="dr-vehicle__name">{{ trim("{$vehicle->color} {$vehicle->make} {$vehicle->model}") }}</span>
                        <span class="dr-plate">{{ $vehicle->plate_number }}</span>
                    @else
                        <span class="dr-vehicle__name">No active vehicle</span>
                    @endif
                </div>
                <a class="dr-textlink dr-optional" href="{{ route('drivers.vehicles.index') }}">{{ $state->vehicles()->count() > 1 ? 'Change' : ($vehicle ? 'Vehicle' : 'Add a vehicle') }}</a>
            </div>

            @if ($eligible)
                <button type="button" class="dr-golive" data-dr-go-online>
                    <x-eh.icon name="online" />
                    <span>Go online</span>
                </button>
                <p class="dr-note" data-dr-location-note>Going online asks for your phone's location. It is sent to Dot.Ehail every few seconds while you are online, and never when you are offline.</p>
            @else
                <div class="dr-checks" aria-labelledby="dr-checks-h">
                    <h3 class="dr-checks__title" id="dr-checks-h">You can go online when:</h3>
                    <ul class="dr-checks__list">
                        @foreach (\App\Http\Controllers\Driver\Support\DriverState::reasonLines($eligibility['reasons']) as $line)
                            <li @if($line['waiting']) data-waiting @endif><x-eh.icon :name="$line['waiting'] ? 'clock' : 'alert'" /><span>{{ $line['text'] }}</span></li>
                        @endforeach
                    </ul>
                    <div class="dr-checks__actions">
                        @if (collect($eligibility['reasons'])->contains(fn ($r) => str_starts_with($r['code'], 'document')))
                            <x-eh.button size="driver" :href="route('drive.documents')" icon="document-check" block>Fix my documents</x-eh.button>
                        @elseif (collect($eligibility['reasons'])->contains(fn ($r) => str_starts_with($r['code'], 'vehicle') || $r['code'] === 'no_active_vehicle'))
                            <x-eh.button size="driver" :href="route('drivers.vehicles.index')" icon="car" block>Open my vehicles</x-eh.button>
                        @else
                            <x-eh.button size="driver" :href="route('drive.onboarding')" icon="list" block>See what is missing</x-eh.button>
                        @endif
                    </div>
                </div>
                <button type="button" class="dr-golive" disabled aria-describedby="dr-checks-h">
                    <x-eh.icon name="online" />
                    <span>Go online</span>
                </button>
            @endif
        </section>

        {{-- ONLINE: waiting for offers --}}
        <section class="dr-view" data-dr-view="online" @if($initial !== 'online') hidden @endif aria-labelledby="dr-online-h">
            <h2 class="eh-sr-only" id="dr-online-h">Waiting for offers</h2>
            <div class="dr-wait">
                <div class="dr-wait__line" aria-hidden="true"><span></span></div>
                <dl class="dr-facts">
                    <div><dt>Location</dt><dd data-dr-loc-status>Waiting for your phone's position</dd></div>
                    <div><dt>Connection</dt><dd data-dr-conn-status>Checking for offers</dd></div>
                    @if ($vehicle)<div><dt>Vehicle</dt><dd>{{ $vehicle->plate_number }}</dd></div>@endif
                </dl>
            </div>
            <div class="dr-actions">
                <button type="button" class="dr-golive dr-golive--off" data-dr-go-offline>
                    <x-eh.icon name="offline" />
                    <span>Go offline</span>
                </button>
                <div class="dr-actions__row dr-optional">
                    <button type="button" class="eh-btn eh-btn--secondary eh-btn--driver" data-dr-pause>Pause offers</button>
                    <button type="button" class="eh-btn eh-btn--secondary eh-btn--driver" data-dr-resume hidden>Resume offers</button>
                    @if (Route::has('rides.available'))
                        <a class="eh-btn eh-btn--ghost eh-btn--driver" href="{{ route('rides.available') }}">Open board</a>
                    @endif
                </div>
            </div>
        </section>

        {{-- TRIP --}}
        <section class="dr-view" data-dr-view="trip" @if($initial !== 'trip') hidden @endif aria-labelledby="dr-trip-h">
            <ol class="dr-route" data-dr-route aria-label="Trip progress">
                <li data-step="pickup"><span class="dr-route__dot" aria-hidden="true"></span><span>Pickup</span></li>
                <li data-step="onboard"><span class="dr-route__dot" aria-hidden="true"></span><span>Passenger in</span></li>
                <li data-step="dropoff"><span class="dr-route__dot" aria-hidden="true"></span><span>Drop-off</span></li>
            </ol>

            <div class="dr-stop">
                <p class="dr-stop__kicker" data-dr-stop-label>Pickup</p>
                <h2 class="dr-stop__address" id="dr-trip-h" data-dr-stop-address></h2>
                <p class="dr-stop__meta"><span data-dr-stop-distance></span> <span class="dr-passenger" data-dr-passenger></span></p>
            </div>

            <div class="dr-trip-actions">
                <a class="eh-btn eh-btn--secondary eh-btn--driver eh-btn--block" data-dr-navigate href="#" target="_blank" rel="noopener">
                    <x-eh.icon name="navigation" /><span class="eh-btn__label" data-dr-navigate-label>Navigate</span>
                </a>

                {{-- Heading to pickup --}}
                <div data-dr-stage="arriving">
                    <button type="button" class="dr-primary" data-dr-arrived>I've arrived</button>
                    <p class="dr-note" data-dr-arrived-hint></p>
                </div>

                {{-- At pickup: waiting, PIN, start --}}
                <div data-dr-stage="arrived" hidden>
                    <div class="dr-waiting">
                        <span class="dr-waiting__label">Waiting</span>
                        <span class="dr-waiting__clock" data-dr-wait-clock>0:00</span>
                    </div>
                    <p class="dr-note" data-dr-noshow-note></p>

                    <div class="dr-pin" data-dr-pin hidden>
                        <p class="dr-pin__label" id="dr-pin-label">Ask the passenger for their 4-digit PIN</p>
                        <div class="dr-pin__boxes" aria-labelledby="dr-pin-label" data-dr-pin-boxes role="status">
                            <span></span><span></span><span></span><span></span>
                        </div>
                        <p class="dr-pin__error" data-dr-pin-error role="alert"></p>
                        <div class="dr-keypad" role="group" aria-label="PIN keypad">
                            @foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $digit)
                                <button type="button" data-dr-key="{{ $digit }}">{{ $digit }}</button>
                            @endforeach
                            <button type="button" data-dr-key-clear aria-label="Clear PIN">Clear</button>
                            <button type="button" data-dr-key="0">0</button>
                            <button type="button" data-dr-key-back aria-label="Delete last digit"><x-eh.icon name="arrow-left" /></button>
                        </div>
                    </div>
                    <button type="button" class="dr-primary" data-dr-start>Start trip</button>
                    <button type="button" class="eh-btn eh-btn--ghost eh-btn--driver eh-btn--block dr-optional" data-dr-noshow disabled>Passenger did not come</button>
                </div>

                {{-- In the trip --}}
                <div data-dr-stage="in_progress" hidden>
                    <button type="button" class="dr-primary" data-dr-complete>Complete trip</button>
                </div>
            </div>

            <div class="dr-map dr-optional" data-dr-map-wrap>
                <x-eh.map-frame id="dr-map" label="Map of your next stop" height="14rem" message="The map loads when you open it." />
                <button type="button" class="eh-btn eh-btn--ghost eh-btn--md" data-dr-map-show>Show map</button>
            </div>

            <details class="dr-more dr-optional">
                <summary>Trip options</summary>
                <div class="dr-more__body">
                    <p class="dr-note">Cancelling after accepting returns the ride to other drivers when the platform allows it. It is recorded on your account.</p>
                    <button type="button" class="eh-btn eh-btn--secondary eh-btn--md" data-eh-dialog-open="dr-cancel-dialog">Cancel this ride</button>
                </div>
            </details>
        </section>

        {{-- TRIP SUMMARY --}}
        <section class="dr-view" data-dr-view="summary" hidden aria-labelledby="dr-summary-h">
            <h2 class="dr-summary__title" id="dr-summary-h">Trip complete</h2>
            <div class="dr-cash" data-dr-cash hidden>
                <p class="dr-cash__ask">Collect cash</p>
                <p class="dr-cash__amount" data-dr-cash-amount></p>
                <form class="dr-cash__form" data-dr-cash-form>
                    <label class="eh-field__label" for="dr-cash-received">Amount you received (rand)</label>
                    <input class="eh-input eh-input--driver" id="dr-cash-received" name="received" inputmode="decimal" autocomplete="off" required>
                    <p class="dr-note">If you received less, enter what you got. Support follows up with the passenger; you are not blamed for the difference.</p>
                    <button type="submit" class="dr-primary">Confirm cash received</button>
                </form>
                <p class="dr-cash__done" data-dr-cash-done hidden></p>
            </div>
            <dl class="dr-ledger" data-dr-summary-ledger></dl>
            <p class="dr-note" data-dr-summary-note></p>
            <div class="dr-actions__row">
                <button type="button" class="dr-primary" data-dr-summary-done>Back to offers</button>
                <a class="eh-btn eh-btn--ghost eh-btn--driver" data-dr-summary-link href="#">Trip details</a>
            </div>
        </section>

        <aside class="dr-aside dr-optional" aria-labelledby="dr-today-h">
            <h2 class="dr-aside__title" id="dr-today-h">Today</h2>
            <p class="dr-today">
                <x-eh.money :minor="(int) ($today['net_minor'] ?? 0)" :currency="$currency" size="xl" />
                <span class="dr-today__sub">net from {{ $todayTrips }} {{ \Illuminate\Support\Str::plural('trip', $todayTrips) }}</span>
            </p>
            <a class="dr-textlink" href="{{ route('drive.earnings') }}">Earnings and payouts</a>
        </aside>
    </div>

    {{-- OFFER: full screen, above everything --}}
    <div class="dr-offer" data-dr-offer hidden role="dialog" aria-modal="true" aria-labelledby="dr-offer-title" aria-describedby="dr-offer-earn">
        <div class="dr-offer__clock">
            <div class="dr-countdown" aria-hidden="true"><span data-dr-countdown-fill></span></div>
            <p class="dr-offer__seconds"><span data-dr-countdown-seconds>15</span> s to answer</p>
        </div>
        <div class="dr-offer__body">
            <h2 class="dr-offer__title" id="dr-offer-title">New ride offer</h2>
            <div class="dr-offer__earn" id="dr-offer-earn">
                <p class="dr-offer__net" data-dr-offer-net></p>
                <p class="dr-offer__net-label" data-dr-offer-net-label>You earn</p>
            </div>
            <dl class="dr-ledger dr-ledger--offer" data-dr-offer-ledger></dl>
            <p class="dr-offer__plan" data-dr-offer-plan hidden>The commission plan is an example the operator has not replaced yet.</p>
            <ol class="dr-legs">
                <li class="dr-legs__pickup">
                    <span class="dr-legs__dot" aria-hidden="true"></span>
                    <span class="dr-legs__what">Pickup <strong data-dr-offer-pickup-distance></strong></span>
                    <span class="dr-legs__where" data-dr-offer-pickup></span>
                </li>
                <li class="dr-legs__dropoff">
                    <span class="dr-legs__dot" aria-hidden="true"></span>
                    <span class="dr-legs__what">Trip <strong data-dr-offer-trip></strong></span>
                    <span class="dr-legs__where" data-dr-offer-dropoff></span>
                </li>
            </ol>
            <p class="dr-offer__tags" data-dr-offer-tags></p>
        </div>
        <div class="dr-offer__actions">
            <button type="button" class="dr-accept" data-dr-accept>Accept</button>
            <button type="button" class="dr-decline" data-dr-decline>Decline</button>
        </div>
        <form class="dr-offer__reasons" data-dr-decline-form hidden>
            <fieldset>
                <legend>Why are you declining? (optional)</legend>
                @foreach (['too_far' => 'Pickup is too far', 'destination' => 'Destination does not suit me', 'safety' => 'I do not feel safe with this trip', 'busy' => 'I am busy', 'other' => 'Something else'] as $value => $label)
                    <label class="dr-reason"><input type="radio" name="reason" value="{{ $value }}"> {{ $label }}</label>
                @endforeach
            </fieldset>
            <div class="dr-actions__row">
                <button type="submit" class="eh-btn eh-btn--primary eh-btn--driver">Decline offer</button>
                <button type="button" class="eh-btn eh-btn--ghost eh-btn--driver" data-dr-decline-back>Back</button>
            </div>
        </form>
    </div>

    {{-- SOS: one tap, then 3 seconds to cancel, then the incident is raised. --}}
    <div class="dr-sos" data-dr-sos hidden role="alertdialog" aria-modal="true" aria-labelledby="dr-sos-title">
        <div data-dr-sos-arming>
            <h2 class="dr-sos__title" id="dr-sos-title">Sending SOS in <span data-dr-sos-count>3</span></h2>
            <p>Dot.Ehail will record an emergency, alert the operations team and your confirmed trusted contacts.</p>
            <button type="button" class="dr-sos__cancel" data-dr-sos-cancel>Cancel</button>
        </div>
        <div data-dr-sos-result hidden>
            <h2 class="dr-sos__title">What we did</h2>
            <ul class="dr-sos__done" data-dr-sos-done></ul>
            <p class="dr-sos__not" data-dr-sos-not></p>
            <x-eh.banner tone="safety" title="Call for help now if you are in danger" emergency />
            <button type="button" class="eh-btn eh-btn--secondary eh-btn--driver eh-btn--block" data-dr-sos-close>Close</button>
        </div>
    </div>

    <x-eh.dialog id="dr-complete-dialog" title="Complete this trip?">
        <p style="margin:0">Only complete the trip once the passenger is at the drop-off.</p>
        <x-slot:footer>
            <button type="button" class="eh-btn eh-btn--ghost eh-btn--lg" data-eh-dialog-close>Not yet</button>
            <button type="button" class="eh-btn eh-btn--primary eh-btn--lg" data-dr-complete-confirm>Complete trip</button>
        </x-slot:footer>
    </x-eh.dialog>

    <x-eh.dialog id="dr-far-dialog" title="Are you at the pickup?">
        <p style="margin:0" data-dr-far-text></p>
        <p class="dr-note">If GPS is wrong (tall buildings, an underground garage), you can still mark it. This is recorded on the trip.</p>
        <x-slot:footer>
            <button type="button" class="eh-btn eh-btn--ghost eh-btn--lg" data-eh-dialog-close>Keep driving</button>
            <button type="button" class="eh-btn eh-btn--primary eh-btn--lg" data-dr-far-confirm>I am at the pickup</button>
        </x-slot:footer>
    </x-eh.dialog>

    <x-eh.dialog id="dr-noshow-dialog" title="Record a no-show?">
        <p style="margin:0">The ride ends and the passenger is told they did not come. Any no-show fee follows the fare rules shown to the passenger.</p>
        <x-slot:footer>
            <button type="button" class="eh-btn eh-btn--ghost eh-btn--lg" data-eh-dialog-close>Keep waiting</button>
            <button type="button" class="eh-btn eh-btn--danger eh-btn--lg" data-dr-noshow-confirm>Record no-show</button>
        </x-slot:footer>
    </x-eh.dialog>

    <x-eh.dialog id="dr-cancel-dialog" title="Cancel this ride?">
        <form data-dr-cancel-form>
            <fieldset class="dr-fieldset">
                <legend class="eh-field__label">Reason</legend>
                @foreach (\App\Enums\CancelReason::forActor(\App\Enums\RideActor::Driver) as $reason)
                    <label class="dr-reason"><input type="radio" name="reason" value="{{ $reason->value }}" required> {{ $reason->label() }}</label>
                @endforeach
            </fieldset>
        </form>
        <x-slot:footer>
            <button type="button" class="eh-btn eh-btn--ghost eh-btn--lg" data-eh-dialog-close>Keep the ride</button>
            <button type="button" class="eh-btn eh-btn--danger eh-btn--lg" data-dr-cancel-confirm>Cancel ride</button>
        </x-slot:footer>
    </x-eh.dialog>
</x-driver-layout>
