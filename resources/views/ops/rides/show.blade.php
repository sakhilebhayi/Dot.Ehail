@php
    use App\Domain\Ops\Support\Durations;
    use App\Domain\Ops\Support\TimelineWords;
    $currency = $ride->currency ?? 'ZAR';
    $events = $ride->events->sortBy('sequence')->values();
    $lastStatusSeq = optional($events->where('type', 'transition')->last())->sequence;
    $route = \App\Domain\Geo\Models\RideRoute::query()->where('ride_id', $ride->id)->first();
    $holdsDriver = $status->holdsDriver();
    $canReassign = in_array('searching', $operatorEdges, true) || in_array('driver_assigned', $operatorEdges, true);
    $canCancel = in_array('cancelled_by_operator', $operatorEdges, true);
    $canFlag = in_array('needs_review', $operatorEdges, true);
    $isReview = $ride->status === 'needs_review';
    $elapsed = $stateSince ? (int) $stateSince->diffInSeconds(now(), true) : null;
    $finding = match (true) {
        $isReview => ['Flagged for review'.($ride->needs_review_reason ? ': '.str_replace('_', ' ', $ride->needs_review_reason) : '').'. Decide how it ends.', 'stop'],
        $stuck => ['No change for '.Durations::short($elapsed).' in this state. It is past the limit.', 'stop'],
        $status->isAwaitingDriver() && $ride->search_started_at => ['Looking for a driver for '.Durations::short($elapsed).'; '.$ride->offers_sent.' '.\Illuminate\Support\Str::plural('offer', $ride->offers_sent).' sent.', 'caution'],
        $holdsDriver => [$status->label().' for '.Durations::short($elapsed).'.', null],
        default => [$status->label().($ride->updated_at ? ', last changed '.$ride->updated_at->format('d M Y H:i') : '').'.', null],
    };
    $fmtComponents = fn ($components) => collect(is_array($components) ? $components : [])->filter(fn ($c) => is_array($c) && isset($c['amount_minor']));
@endphp
<x-ops-layout :title="'Ride '.$ride->id">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <p class="ops-crumb"><a href="{{ route('ops.rides') }}">Rides</a> / Ride {{ $ride->id }}</p>
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Ride {{ $ride->id }} <x-eh.status :status="$ride->status" style="vertical-align:middle" /></h1>
                <p class="ops-head__finding" @if($finding[1]) data-tone="{{ $finding[1] }}" @endif>{{ $finding[0] }}</p>
            </div>
        </header>

        <div class="ops-split">
            <div class="ops-stack">
                <section class="ops-panel ops-panel--flush" aria-labelledby="map-h">
                    <div class="ops-panel__head" style="padding-bottom:var(--eh-space-3)"><h2 id="map-h">Route</h2>
                        <span class="ops-panel__meta">{{ $ride->pickup_address ?? 'Pickup not recorded' }} to {{ $ride->dropoff_address ?? 'drop-off not recorded' }}</span>
                    </div>
                    @if ($ride->pickup_lat !== null)
                        <div class="ops-map" style="height:20rem" data-ops-ride-map
                             data-pickup="{{ json_encode(['lat' => (float) $ride->pickup_lat, 'lng' => (float) $ride->pickup_lng]) }}"
                             data-dropoff="{{ $ride->dropoff_lat !== null ? json_encode(['lat' => (float) $ride->dropoff_lat, 'lng' => (float) $ride->dropoff_lng]) : '' }}"
                             data-polyline="{{ $route?->planned_polyline }}"
                             @if($positionsAllowed && $holdsDriver) data-driver-url="{{ route('geo.rides.driver-location', $ride->id) }}" @endif>
                            <div data-eh-map-canvas aria-label="Map of ride {{ $ride->id }}"></div>
                            <p class="ops-map__status" data-ops-map-status role="status">Loading the map.</p>
                        </div>
                    @else
                        <p class="ops-none" style="padding:0 var(--eh-space-5) var(--eh-space-4)">This ride was booked by address only (before R-46); there are no coordinates to draw.</p>
                    @endif
                </section>

                <section class="ops-panel" aria-labelledby="timeline-h">
                    <div class="ops-panel__head"><h2 id="timeline-h">Timeline</h2><span class="ops-panel__meta">{{ $events->count() }} {{ \Illuminate\Support\Str::plural('event', $events->count()) }} from ride_events, oldest first</span></div>
                    @if ($events->isEmpty())
                        <p class="ops-none">No timeline was recorded for this ride (it predates the R-46 timeline).</p>
                    @else
                        <ol class="ops-route">
                            @foreach ($events as $event)
                                @php($words = TimelineWords::describe($event))
                                <li data-kind="{{ $words['kind'] }}" @if($event->sequence === $lastStatusSeq) data-current aria-current="step" @endif>
                                    <span class="ops-route__stop" aria-hidden="true"></span>
                                    <span class="ops-route__what">{{ $words['what'] }}</span>
                                    <time class="ops-route__when" datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->format('d M H:i:s') }}</time>
                                    @if ($words['detail'])<span class="ops-route__detail">{{ $words['detail'] }}</span>@endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                @if ($ride->offers->isNotEmpty())
                    <section class="ops-panel ops-panel--flush" aria-labelledby="offers-h">
                        <div class="ops-panel__head"><h2 id="offers-h">Offers</h2><span class="ops-panel__meta">ETA method shown per offer; straight-line estimates rank only</span></div>
                        <div class="ops-tablewrap"><table class="ops-table ops-table--compact">
                            <thead><tr><th>Driver</th><th>Outcome</th><th class="num">Round</th><th class="num">Pickup</th><th class="num">ETA</th><th class="num">Score</th><th class="num">Sent</th><th class="num">Answered</th></tr></thead>
                            <tbody>
                                @foreach ($ride->offers->sortBy('id') as $offer)
                                    <tr>
                                        <td>@can('ops.drivers.view')<a href="{{ route('ops.drivers.show', $offer->driver_id) }}">{{ $offer->driver?->name ?? 'Driver '.$offer->driver_id }}</a>@else{{ $offer->driver?->name ?? 'Driver '.$offer->driver_id }}@endcan</td>
                                        <td>{{ ucfirst($offer->status) }}@if($offer->reason)<span class="sub">{{ str_replace('_', ' ', $offer->reason) }}</span>@endif</td>
                                        <td class="num">{{ $offer->round }}</td>
                                        <td class="num">{{ $offer->pickup_distance_m !== null ? number_format($offer->pickup_distance_m / 1000, 1).' km' : '-' }}</td>
                                        <td class="num">{{ $offer->pickup_eta_s !== null ? Durations::short($offer->pickup_eta_s) : '-' }}<span class="sub">{{ str_replace('_', ' ', (string) $offer->eta_method) }}</span></td>
                                        <td class="num">{{ $offer->score !== null ? number_format((float) $offer->score, 1) : '-' }}</td>
                                        <td class="num">{{ $offer->sent_at?->format('H:i:s') ?? '-' }}</td>
                                        <td class="num">{{ $offer->responded_at?->format('H:i:s') ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                    </section>
                @endif
            </div>

            <div class="ops-stack">
                @if ($canIntervene && ($canReassign || $canCancel || $canFlag || $isReview))
                    <section class="ops-panel" aria-labelledby="act-h">
                        <div class="ops-panel__head"><h2 id="act-h">Intervene</h2><span class="ops-panel__meta">each needs a reason; audited</span></div>
                        <div class="ops-actions">
                            @if ($isReview)
                                <details open>
                                    <summary>Resolve <span class="ops-hint">end the review</span></summary>
                                    <form class="ops-act" data-ops-action method="post" action="{{ route('ehail.dispatch.ops.rides.resolve', $ride->id) }}" data-ops-done="Ride {{ $ride->id }} resolved.">
                                        <x-eh.select label="Outcome" name="outcome" :options="['complete' => 'Complete the trip', 'cancel' => 'Cancel the trip', 'redispatch' => 'Find another driver (trip not started)']" value="complete" id="resolve-outcome" />
                                        <x-eh.field label="Final fare in rand (for complete)" name="final_fare_rand" type="text" inputmode="decimal" data-ops-minor="final_fare_minor" id="resolve-fare" :optional="true" hint="Leave empty to keep the fare already agreed." />
                                        <x-eh.field label="Reason" name="reason" type="textarea" required minlength="3" maxlength="500" id="resolve-reason" />
                                        <div class="ops-act__row"><x-eh.button type="submit" size="sm">Resolve</x-eh.button></div>
                                    </form>
                                </details>
                            @endif
                            @if ($canReassign)
                                <details @if(! $isReview && $holdsDriver) open @endif>
                                    <summary>Reassign <span class="ops-hint">{{ $holdsDriver || $isReview ? 'back to searching, or to a named driver' : 'to a named driver' }}</span></summary>
                                    <form class="ops-act" data-ops-action method="post" action="{{ route('ehail.dispatch.ops.rides.reassign', $ride->id) }}" data-ops-done="Ride {{ $ride->id }} reassigned.">
                                        <x-eh.field label="Driver user number" name="driver_id" type="number" min="1" id="reassign-driver" :optional="$holdsDriver || $isReview" hint="Empty puts the ride back into search with priority." />
                                        <x-eh.field label="Reason" name="reason" type="textarea" required minlength="3" maxlength="500" id="reassign-reason" />
                                        <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Reassign</x-eh.button></div>
                                    </form>
                                </details>
                            @endif
                            @if ($canFlag)
                                <details>
                                    <summary>Flag for review <span class="ops-hint">stops dispatch until someone decides</span></summary>
                                    <form class="ops-act" data-ops-action method="post" action="{{ route('ehail.dispatch.ops.rides.needs-review', $ride->id) }}" data-ops-done="Ride {{ $ride->id }} flagged for review.">
                                        <x-eh.field label="Reason" name="reason" type="textarea" required minlength="3" maxlength="500" id="flag-reason" />
                                        <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Flag for review</x-eh.button></div>
                                    </form>
                                </details>
                            @endif
                            @if ($canCancel)
                                <details>
                                    <summary>Cancel the ride <span class="ops-hint">both people are told</span></summary>
                                    <form class="ops-act" data-ops-action method="post" action="{{ route('ehail.dispatch.ops.rides.force-cancel', $ride->id) }}" data-ops-confirm="Cancel ride {{ $ride->id }}? Both the passenger and the driver are told." data-ops-done="Ride {{ $ride->id }} cancelled.">
                                        <x-eh.field label="Reason (recorded on the ride)" name="reason" type="textarea" required minlength="3" maxlength="500" id="cancel-reason" />
                                        <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="danger">Cancel ride</x-eh.button></div>
                                    </form>
                                </details>
                            @endif
                        </div>
                    </section>
                @endif

                <section class="ops-panel" aria-labelledby="facts-h">
                    <div class="ops-panel__head"><h2 id="facts-h">Ride</h2></div>
                    <dl class="ops-facts">
                        <dt>Passenger</dt><dd>{{ $ride->passenger?->name ?? 'unknown' }} <span class="ops-stamp">user {{ $ride->passenger_id }}</span></dd>
                        <dt>Driver</dt><dd>@if($ride->driver_id)@can('ops.drivers.view')<a class="eh-link" href="{{ route('ops.drivers.show', $ride->driver_id) }}">{{ $ride->driver?->name }}</a>@else{{ $ride->driver?->name }}@endcan @else<span class="ops-dash">none assigned</span>@endif</dd>
                        <dt>Vehicle</dt><dd>{{ $ride->vehicle ? trim($ride->vehicle->color.' '.$ride->vehicle->make.' '.$ride->vehicle->model).' · '.$ride->vehicle->plate_number : '-' }}</dd>
                        <dt>Category</dt><dd>{{ \App\Domain\Business\CategoryNames::for($ride->category_code ?? $ride->vehicle_type) }}</dd>
                        <dt>Requested</dt><dd class="num">{{ ($ride->requested_at ?? $ride->created_at)?->format('d M Y H:i') }}</dd>
                        @if ($ride->scheduled_for)<dt>Scheduled for</dt><dd class="num">{{ $ride->scheduled_for->format('d M Y H:i') }}</dd>@endif
                        @if ($ride->route_distance_m)<dt>Quoted route</dt><dd class="num">{{ number_format($ride->route_distance_m / 1000, 1) }} km · {{ Durations::short((int) $ride->route_duration_s) }}</dd>@endif
                        @if ($route?->travelled_m)<dt>Travelled</dt><dd class="num">{{ number_format($route->travelled_m / 1000, 1) }} km <span class="ops-stamp">{{ str_replace('_', ' ', (string) $route->distance_method) }}, {{ $route->distance_quality }}</span></dd>@endif
                        <dt>Search</dt><dd class="num">{{ $ride->offers_sent }} {{ \Illuminate\Support\Str::plural('offer', $ride->offers_sent) }}, round {{ $ride->search_round ?? 0 }}, handed back {{ $ride->reassignment_count }}x</dd>
                        @if ($ride->cancel_reason || $ride->cancel_note)<dt>Cancelled because</dt><dd>{{ str_replace('_', ' ', (string) $ride->cancel_reason) }} {{ $ride->cancel_note }}</dd>@endif
                        @if ($ride->failure_reason)<dt>Failure</dt><dd>{{ $ride->failure_reason }}</dd>@endif
                    </dl>
                </section>

                <section class="ops-panel" aria-labelledby="fare-h">
                    <div class="ops-panel__head"><h2 id="fare-h">Fare</h2>
                        @if ($fare)<span class="ops-panel__meta">tariff {{ $fare->rule_set_id }} v{{ $fare->rule_version }}{{ $fare->basis ? ' · '.str_replace('_', ' ', $fare->basis) : '' }}</span>@endif
                    </div>
                    @if ($fare)
                        @php($lines = $fare->final_total_minor !== null ? $fmtComponents($fare->final_components) : $fmtComponents($fare->estimated_components))
                        <table class="ops-table ops-table--compact" style="margin:0 calc(-1 * var(--eh-space-5));width:calc(100% + 2 * var(--eh-space-5))">
                            <tbody>
                                @foreach ($lines as $line)
                                    <tr><td>{{ $line['label'] ?? str_replace('_', ' ', (string) ($line['code'] ?? '')) }}</td><td class="num"><x-eh.money :minor="(int) $line['amount_minor']" :currency="$fare->currency ?? $currency" /></td></tr>
                                @endforeach
                                <tr><td><strong>{{ $fare->final_total_minor !== null ? 'Final fare' : 'Quoted fare' }}</strong></td><td class="num"><strong><x-eh.money :minor="(int) ($fare->final_total_minor ?? $fare->estimated_total_minor)" :currency="$fare->currency ?? $currency" /></strong></td></tr>
                                @if ($fare->final_total_minor !== null && $fare->estimated_total_minor !== null)
                                    <tr><td class="muted">Quoted before the trip</td><td class="num muted"><x-eh.money :minor="(int) $fare->estimated_total_minor" :currency="$fare->currency ?? $currency" /></td></tr>
                                @endif
                            </tbody>
                        </table>
                        @foreach ((array) $fare->adjustments as $adj)
                            @if (is_array($adj))<p class="ops-note ops-note--small">{{ $adj['text'] ?? $adj['label'] ?? str_replace('_', ' ', (string) ($adj['reason_code'] ?? $adj['code'] ?? 'adjustment')) }}@isset($adj['amount_minor']) (<x-eh.money :minor="(int) $adj['amount_minor']" :currency="$fare->currency ?? $currency" signed />)@endisset</p>@endif
                        @endforeach
                    @elseif ($ride->final_fare !== null || $ride->estimated_fare !== null)
                        <dl class="ops-facts">
                            @if ($ride->estimated_fare !== null)<dt>Estimate</dt><dd class="num">{{ \App\Services\Ehail\FareCalculator::format($ride->estimated_fare) }}</dd>@endif
                            @if ($ride->final_fare !== null)<dt>Recorded fare</dt><dd class="num">{{ \App\Services\Ehail\FareCalculator::format($ride->final_fare) }}</dd>@endif
                        </dl>
                        <p class="ops-note ops-note--small">Recorded before R-46 pricing: there is no itemised snapshot.</p>
                    @else
                        <p class="ops-none">No fare is recorded on this ride.</p>
                    @endif

                    @if ($canAdjustFare)
                        <div class="ops-actions" style="margin-top:var(--eh-space-3)">
                            <details>
                                <summary>Adjust the fare <span class="ops-hint">the passenger reads your text</span></summary>
                                <form class="ops-act" data-ops-action method="post" action="{{ route('pricing.ops.rides.fare-adjustments.store', $ride->id) }}" data-ops-done="Fare adjusted.">
                                    <x-eh.field label="New total in rand" name="new_total_rand" type="text" inputmode="decimal" data-ops-minor="new_total_minor" required id="adj-total" />
                                    <x-eh.select label="Reason" name="reason_code" :options="collect($adjustReasons)->mapWithKeys(fn ($r) => [$r => ucfirst(str_replace('_', ' ', $r))])->all()" id="adj-reason" />
                                    <x-eh.field label="What the passenger is told" name="text" type="textarea" required minlength="5" maxlength="500" id="adj-text" />
                                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" variant="secondary">Adjust fare</x-eh.button></div>
                                </form>
                            </details>
                        </div>
                    @endif
                </section>

                <section class="ops-panel ops-panel--flush" aria-labelledby="payments-h">
                    <div class="ops-panel__head"><h2 id="payments-h">Payments</h2><span class="ops-panel__meta">status {{ $ride->paymentStatusEnum()->label() }}</span></div>
                    @if ($payments->isEmpty())
                        <p class="ops-none">No payment attempt is recorded.</p>
                    @else
                        <table class="ops-table ops-table--compact">
                            <thead><tr><th>Attempt</th><th>Status</th><th class="num">Amount</th><th class="num">Refunded</th></tr></thead>
                            <tbody>
                                @foreach ($payments as $payment)
                                    <tr>
                                        <td>@if($showPaymentDetail)<a href="{{ route('ops.finance.payment', $payment->id) }}">{{ str_replace('_', ' ', (string) ($payment->purpose ?? 'fare')) }} {{ $payment->attempt }}</a>@else{{ str_replace('_', ' ', (string) ($payment->purpose ?? 'fare')) }} {{ $payment->attempt }}@endif<span class="sub">{{ $payment->channel ?? $payment->provider }}</span></td>
                                        <td>{{ ucfirst((string) $payment->status) }}</td>
                                        <td class="num"><x-eh.money :minor="(int) $payment->amount_cents" :currency="$payment->currency ?? $currency" /></td>
                                        <td class="num">@if($payment->refunded_minor)<x-eh.money :minor="(int) $payment->refunded_minor" :currency="$payment->currency ?? $currency" />@else<span class="ops-dash">-</span>@endif</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>

                @if ($incidents !== null)
                    <section class="ops-panel ops-panel--flush" aria-labelledby="inc-h">
                        <div class="ops-panel__head"><h2 id="inc-h">Safety incidents</h2></div>
                        @forelse ($incidents as $incident)
                            <ul class="ops-queue"><li>
                                <a class="ops-queue__title" href="{{ route('ops.safety.show', $incident->id) }}">{{ $incident->reference }} · {{ str_replace('_', ' ', $incident->kind instanceof \BackedEnum ? $incident->kind->value : (string) $incident->kind) }}</a>
                                <span class="ops-queue__meta">{{ ucfirst($incident->severity instanceof \BackedEnum ? $incident->severity->value : (string) $incident->severity) }} · {{ $incident->status instanceof \BackedEnum ? $incident->status->value : $incident->status }}</span>
                            </li></ul>
                        @empty
                            <p class="ops-none">No incident on this ride.</p>
                        @endforelse
                    </section>
                @endif

                @if ($cases !== null)
                    <section class="ops-panel ops-panel--flush" aria-labelledby="cases-h">
                        <div class="ops-panel__head"><h2 id="cases-h">Support cases</h2></div>
                        @forelse ($cases as $case)
                            <ul class="ops-queue"><li>
                                <a class="ops-queue__title" href="{{ route('ops.support.show', $case->id) }}">{{ $case->reference }} · {{ $case->subject }}</a>
                                <span class="ops-queue__meta">{{ $case->category->label() }} · {{ str_replace('_', ' ', $case->status->value) }}</span>
                            </li></ul>
                        @empty
                            <p class="ops-none">No support case on this ride.</p>
                        @endforelse
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-ops-layout>
