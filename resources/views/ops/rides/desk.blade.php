@php
    $reviewCount = $needsReview->count();
    $waitMinutes = $oldestOpenRequest ? (int) \Carbon\Carbon::parse($oldestOpenRequest)->diffInMinutes(now(), true) : null;
    $fmt = fn ($v) => \App\Services\Ehail\FareCalculator::format($v);
@endphp
<x-ops-layout title="Rides">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Rides</h1>
                <p class="ops-head__finding" data-state="{{ $reviewCount > 0 ? 'needs-review' : 'clear' }}" @if($reviewCount > 0) data-tone="stop" @endif>
                    @if ($reviewCount > 0)
                        {{ $reviewCount }} {{ \Illuminate\Support\Str::plural('trip', $reviewCount) }} {{ $reviewCount === 1 ? 'needs' : 'need' }} a decision.
                    @else
                        Nothing needs review.
                    @endif
                    @if ($stuck->count() > 0) {{ $stuck->count() }} stuck. @endif
                </p>
            </div>
            <div class="ops-head__actions">
                @can('ops.live.view')<x-eh.button size="sm" variant="secondary" :href="route('ops.live')" icon="map">Live map</x-eh.button>@endcan
            </div>
        </header>
        @include('ops.rides.tabs', ['active' => 'desk'])
        @include('ops.partials.flash')

        <dl class="ops-figures" aria-label="Queue">
            <div data-tone="{{ $openRequests > 0 ? 'caution' : '' }}"><dt>Open ride requests</dt><dd data-figure="open-requests">{{ number_format($openRequests) }}<small>{{ $waitMinutes !== null ? 'oldest '.$waitMinutes.' min' : '' }}</small></dd></div>
            @can('ops.fleets.review')
                <div><a href="{{ route('ops.fleets') }}"><dt>Fleets awaiting approval</dt><dd data-figure="pending-fleets">{{ number_format($pendingFleets) }}</dd></a></div>
                <div><a href="{{ route('ops.drivers', ['status' => 'pending']) }}"><dt>Driver applications pending</dt><dd data-figure="pending-applications">{{ number_format($pendingApplications) }}</dd></a></div>
            @endcan
            <div><dt>Requests expired in the last 7 days</dt><dd data-figure="expired-week">{{ number_format($expiredLastWeek) }}</dd></div>
            <div><dt title="A sum of recorded fares on {{ number_format($completedCount) }} completed {{ \Illuminate\Support\Str::plural('ride', $completedCount) }}; not money collected">Estimated fare total, not collected</dt><dd data-figure="fare-total">{{ $fmt($completedFareTotal) }}</dd></div>
            <div><dt title="Payments Paystack confirmed: {{ number_format($collectedCount) }}{{ $testMode ? ' (TEST MODE, not real money)' : '' }}">Fares collected through Paystack</dt><dd data-figure="fares-collected">{{ \App\Services\Ehail\FareCalculator::formatCents($collectedCents) }}</dd></div>
        </dl>
        @if ($testMode)<p class="ops-note" style="margin:calc(-1 * var(--eh-space-2)) 0 var(--eh-space-4)"><x-eh.badge tone="caution" icon="warning">TEST MODE</x-eh.badge> Paystack is in test mode: collected figures are not real money.</p>@endif

        <div class="ops-split">
            <div class="ops-stack">
                <section class="ops-panel ops-panel--flush" aria-labelledby="review-h" data-section="needs-review">
                    <div class="ops-panel__head"><h2 id="review-h">Needs review</h2><span class="ops-panel__meta">in progress after {{ config('ehail.stuck_ride_hours') }} h, or flagged; never completed automatically</span></div>
                    <ul class="ops-queue">
                        @forelse ($needsReview as $ride)
                            <li>
                                <a class="ops-queue__title" href="{{ route('ops.rides.show', $ride->id) }}">Ride {{ $ride->id }}: {{ $ride->pickup_address }} to {{ $ride->dropoff_address }}</a>
                                <span class="ops-queue__meta">Driver {{ $ride->driver?->name ?? 'none' }} · passenger {{ $ride->passenger?->name ?? 'unknown' }} · {{ $ride->estimated_fare !== null ? 'estimate '.$fmt($ride->estimated_fare) : 'no fare estimate' }}@if($ride->needs_review_reason) · {{ str_replace('_', ' ', $ride->needs_review_reason) }}@endif</span>
                                <span class="ops-queue__trail"><span class="ops-timer" data-tone="stop" @if($ride->needs_review_at) data-ops-since="{{ $ride->needs_review_at->toIso8601String() }}" data-ops-warn="1" data-ops-label="waiting" @endif>flagged {{ $ride->needs_review_at?->diffForHumans() ?? 'earlier' }}</span></span>
                            </li>
                        @empty
                            <li><p class="ops-none" style="padding:0">Nothing needs review.</p></li>
                        @endforelse
                    </ul>
                </section>

                <section class="ops-panel ops-panel--flush" aria-labelledby="stuck-h">
                    <div class="ops-panel__head"><h2 id="stuck-h">Stuck</h2><span class="ops-panel__meta">assigned or arriving 20 min, at pickup 15 min, on trip 3 h, searching past the window</span></div>
                    <ul class="ops-queue">
                        @forelse ($stuck as $ride)
                            @php($since = $stuckRides->enteredStateAt($ride))
                            <li>
                                <a class="ops-queue__title" href="{{ route('ops.rides.show', $ride->id) }}">Ride {{ $ride->id }}: {{ $ride->pickup_address }} to {{ $ride->dropoff_address }}</a>
                                <span class="ops-queue__meta"><x-eh.status :status="$ride->status" /> {{ $ride->driver?->name ? 'Driver '.$ride->driver->name : '' }}</span>
                                <span class="ops-queue__trail"><span class="ops-timer" data-tone="stop" @if($since) data-ops-since="{{ $since->toIso8601String() }}" data-ops-warn="1" data-ops-label="in this state" @endif>{{ $since ? 'in this state '.\App\Domain\Ops\Support\Durations::short((int) $since->diffInSeconds(now(), true)) : '' }}</span></span>
                            </li>
                        @empty
                            <li><p class="ops-none" style="padding:0">No ride is stuck.</p></li>
                        @endforelse
                    </ul>
                </section>

                <section class="ops-panel ops-panel--flush" aria-labelledby="expired-h">
                    <div class="ops-panel__head"><h2 id="expired-h">Recently expired requests</h2><span class="ops-panel__meta">nobody accepted within {{ config('ehail.ride_expiry_minutes') }} min</span></div>
                    @if ($expired->isEmpty())
                        <p class="ops-none">No requests have expired.</p>
                    @else
                        <div class="ops-tablewrap"><table class="ops-table">
                            <thead><tr><th>Ride</th><th>Passenger</th><th>Category</th><th class="num">Handed back</th><th class="num">Expired</th></tr></thead>
                            <tbody>
                                @foreach ($expired as $ride)
                                    <tr>
                                        <td><a href="{{ route('ops.rides.show', $ride->id) }}">{{ $ride->id }}</a><span class="sub">{{ $ride->pickup_address }} to {{ $ride->dropoff_address }}</span></td>
                                        <td>{{ $ride->passenger?->name ?? 'unknown' }}</td>
                                        <td>{{ ucfirst((string) ($ride->category_code ?? $ride->vehicle_type)) }}</td>
                                        <td class="num">{{ $ride->reassignment_count > 0 ? $ride->reassignment_count.'x' : '0' }}</td>
                                        <td class="num">{{ $ride->expired_at?->diffForHumans() ?? 'earlier' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </section>
            </div>

            <div class="ops-stack">
                <section class="ops-panel" aria-labelledby="pay-h">
                    <div class="ops-panel__head"><h2 id="pay-h">Fare payments</h2></div>
                    @if (! $paymentsEnabled)
                        <p class="ops-note">{{ $paymentNotice }} Rides completed now owe nothing, and the lists below only show what was recorded earlier.</p>
                    @else
                        <p class="ops-note ops-note--small">Passengers pay the platform's Paystack account. Drivers are paid through payout batches in Finance.</p>
                    @endif
                </section>

                <section class="ops-panel ops-panel--flush" aria-labelledby="unpaid-h" data-section="unpaid-fares">
                    <div class="ops-panel__head"><h2 id="unpaid-h">Unpaid fares</h2><span class="ops-panel__meta">completed more than {{ $alertHours }} {{ \Illuminate\Support\Str::plural('hour', $alertHours) }} ago</span></div>
                    <ul class="ops-queue">
                        @forelse ($unpaidFares as $ride)
                            <li style="grid-template-columns:minmax(0,1fr)">
                                <a class="ops-queue__title" href="{{ route('ops.rides.show', $ride->id) }}">Ride {{ $ride->id }}: {{ $ride->pickup_address }} to {{ $ride->dropoff_address }}</a>
                                <span class="ops-queue__meta">{{ $ride->passenger?->name ?? 'unknown' }} · {{ $fmt($ride->final_fare) }} · {{ $ride->paymentStatusEnum()->label() }} · completed {{ $ride->completed_at?->diffForHumans() ?? 'earlier' }}</span>
                                @can('ops.finance.refund')
                                    <form method="POST" action="{{ route('operator.rides.waive', $ride) }}" class="ops-act" data-ops-confirm="Waive this fare? The passenger will owe nothing." style="margin-top:var(--eh-space-2)">
                                        @csrf
                                        <div class="ops-act__row">
                                            <label class="eh-sr" for="waive-{{ $ride->id }}">Why the fare is waived</label>
                                            <input id="waive-{{ $ride->id }}" class="eh-input" style="flex:1 1 12rem" type="text" name="reason" maxlength="500" required placeholder="Why the fare is waived">
                                            <x-eh.button type="submit" size="sm" variant="secondary">Waive fare</x-eh.button>
                                        </div>
                                    </form>
                                @endcan
                            </li>
                        @empty
                            <li><p class="ops-none" style="padding:0">No fare has been unpaid for that long.</p></li>
                        @endforelse
                    </ul>
                </section>

                <section class="ops-panel ops-panel--flush" aria-labelledby="unmatched-h" data-section="unmatched-payments">
                    <div class="ops-panel__head"><h2 id="unmatched-h">Unmatched payments</h2></div>
                    <ul class="ops-queue">
                        @forelse ($unmatchedPayments as $payment)
                            <li style="grid-template-columns:minmax(0,1fr)">
                                <span class="ops-queue__title" style="color:var(--eh-stop)">{{ $payment->reasonLabel() }}</span>
                                <span class="ops-queue__meta">{{ \App\Services\Ehail\FareCalculator::formatCents($payment->amount_cents) }} {{ $payment->currency }} · reference {{ $payment->reference }}@if($payment->ride) · <a href="{{ route('ops.rides.show', $payment->ride->id) }}">ride {{ $payment->ride->id }}</a>@endif · {{ $payment->updated_at?->diffForHumans() }}</span>
                                <span class="ops-queue__meta">The payer may have been charged. Check it on the Paystack dashboard, refund or reconcile there, then record what you did.</span>
                                @can('ops.finance.refund')
                                    <form method="POST" action="{{ route('operator.payments.review', $payment) }}" class="ops-act" style="margin-top:var(--eh-space-2)">
                                        @csrf
                                        <div class="ops-act__row">
                                            <label class="eh-sr" for="review-{{ $payment->id }}">What you did</label>
                                            <input id="review-{{ $payment->id }}" class="eh-input" style="flex:1 1 12rem" type="text" name="note" maxlength="500" required placeholder="What you did">
                                            <x-eh.button type="submit" size="sm" variant="secondary">Mark reviewed</x-eh.button>
                                        </div>
                                    </form>
                                @endcan
                            </li>
                        @empty
                            <li><p class="ops-none" style="padding:0">No payment is waiting for review.</p></li>
                        @endforelse
                    </ul>
                </section>

                <section class="ops-panel ops-panel--flush" aria-labelledby="waived-h" data-section="waived-fares">
                    <div class="ops-panel__head"><h2 id="waived-h">Waived fares</h2><span class="ops-panel__meta">latest {{ $waivedFares->count() }}</span></div>
                    <ul class="ops-queue">
                        @forelse ($waivedFares as $ride)
                            <li style="grid-template-columns:minmax(0,1fr)">
                                <a class="ops-queue__title" href="{{ route('ops.rides.show', $ride->id) }}">Ride {{ $ride->id }} · {{ $fmt($ride->final_fare) }}</a>
                                <span class="ops-queue__meta">{{ $ride->fare_waived_reason }} · by {{ $ride->waivedBy?->name ?? 'an operator' }} {{ $ride->fare_waived_at?->diffForHumans() }}</span>
                            </li>
                        @empty
                            <li><p class="ops-none" style="padding:0">No fare has been waived.</p></li>
                        @endforelse
                    </ul>
                </section>
            </div>
        </div>
    </div>
</x-ops-layout>
