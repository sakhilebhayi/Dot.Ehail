<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <div style="margin-bottom:1.5rem;">
        <a href="{{ route('rides.index') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to rides
        </a>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-size:0.8rem;">
        {{ session('status') }}
    </div>
    @endif
    @if ($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;font-size:0.8rem;">
        @foreach ($errors->all() as $error)
        <div>{{ $error }}</div>
        @endforeach
    </div>
    @endif

    @php
        $nextStage = $ride->statusEnum()->nextStage();
        $viewerRole = $ride->actorFor(auth()->user());
        $cancelReasons = $viewerRole ? \App\Enums\CancelReason::forActor($viewerRole) : [];
        $fare = \App\Services\Ehail\FareCalculator::class;
    @endphp

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">
                Ride #{{ $ride->id }}
            </h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">Requested {{ $ride->created_at->format('l, F j, Y \a\t g:i A') }}</p>
        </div>
        <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;">
            <x-ride-status :status="$ride->status" />

            @can('accept', $ride)
            <form method="POST" action="{{ route('rides.accept', $ride) }}">
                @csrf
                <button type="submit" class="dot-btn dot-btn-primary">Accept Ride</button>
            </form>
            @endcan

            @if($nextStage)
            @can('advance', $ride)
            <form method="POST" action="{{ route('rides.advance', $ride) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="{{ $nextStage->value }}">
                <button type="submit" class="dot-btn dot-btn-primary">Mark {{ strtolower($nextStage->label()) }}</button>
            </form>
            @endcan
            @endif
        </div>
    </div>

    @if($ride->status === 'needs_review')
    <div class="dot-card" style="padding:1rem 1.5rem;margin-bottom:1.25rem;border:1px solid rgba(251,113,133,0.35);">
        <p style="font-size:0.82rem;color:#fb7185;margin:0;">
            This trip has been in progress for over {{ config('ehail.stuck_ride_hours') }} hours and is waiting for a platform operator to decide how it ended.
        </p>
    </div>
    @endif

    @if($ride->status === 'cancelled' && $ride->cancel_reason)
    <div class="dot-card" style="padding:1rem 1.5rem;margin-bottom:1.25rem;">
        <p style="font-size:0.8rem;color:#a1a1aa;margin:0;">
            Cancelled{{ $ride->cancelled_at ? ' '.$ride->cancelled_at->diffForHumans() : '' }}:
            {{ \App\Enums\CancelReason::tryFrom($ride->cancel_reason)?->label() ?? $ride->cancel_reason }}@if($ride->cancel_note) — {{ $ride->cancel_note }}@endif
        </p>
    </div>
    @endif

    @if($ride->status === 'expired')
    <div class="dot-card" style="padding:1rem 1.5rem;margin-bottom:1.25rem;">
        <p style="font-size:0.8rem;color:#a1a1aa;margin:0;">No driver took this request in time. <a href="{{ route('rides.create') }}" style="color:#38bdf8;text-decoration:none;">Request the ride again</a>.</p>
    </div>
    @endif

    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.25rem;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">Pickup</div>
                <div style="font-size:0.85rem;color:#f4f4f5;">{{ $ride->pickup_address }}</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">Dropoff</div>
                <div style="font-size:0.85rem;color:#f4f4f5;">{{ $ride->dropoff_address }}</div>
            </div>
        </div>
        <div style="display:flex;gap:2rem;margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,0.06);flex-wrap:wrap;">
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.35rem;">Passenger</div>
                <div style="font-size:12px;color:#d4d4d8;">{{ $ride->passenger?->name ?? '—' }}</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.35rem;">Driver</div>
                <div style="font-size:12px;color:#d4d4d8;">{{ $ride->driver?->name ?? 'Unassigned' }}</div>
                @if($driverRating && $driverRating['count'] > 0)
                <div style="font-size:11px;color:#71717a;margin-top:2px;"><span class="metric-val" style="color:#f59e0b;" data-figure="driver-rating">{{ number_format((float) $driverRating['average'], 2) }}</span> from {{ $driverRating['count'] }} {{ \Illuminate\Support\Str::plural('rating', $driverRating['count']) }}</div>
                @elseif($driverRating)
                <div style="font-size:11px;color:#71717a;margin-top:2px;">No ratings yet</div>
                @endif
            </div>
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.35rem;">Vehicle</div>
                <div style="font-size:12px;color:#d4d4d8;">{{ $ride->vehicle ? "{$ride->vehicle->make} {$ride->vehicle->model} ({$ride->vehicle->plate_number})" : '—' }}</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.35rem;">Fare</div>
                <div class="metric-val" style="font-size:12px;color:#4ade80;font-weight:600;">
                    @if($ride->final_fare !== null)
                        {{ \App\Services\Ehail\FareCalculator::format($ride->final_fare) }}
                    @elseif($ride->estimated_fare !== null)
                        ~{{ \App\Services\Ehail\FareCalculator::format($ride->estimated_fare) }} (estimated)
                    @else
                        <span style="color:#a1a1aa;font-weight:500;">Fare estimate not available</span>
                    @endif
                </div>
                @if($ride->final_fare === null && $ride->estimated_fare === null)
                <div style="font-size:11px;color:#71717a;margin-top:2px;max-width:220px;">No coordinates were given, so the distance and fare could not be worked out.</div>
                @endif
                @if($ride->final_fare_reason)
                <div style="font-size:11px;color:#71717a;margin-top:2px;max-width:260px;">Entered by an operator: {{ $ride->final_fare_reason }}</div>
                @endif
            </div>
        </div>
    </div>

    @if($ride->status === 'completed')
    @php
        $payState = $ride->paymentStatusEnum();
        $isPassenger = $viewerRole === \App\Enums\RideActor::Passenger;
        $isOperator = auth()->user()->isPlatformOperator();
        $owed = in_array($payState, \App\Enums\PaymentStatus::collectable(), true);
        $stateColors = ['paid' => '#4ade80', 'waived' => '#a1a1aa', 'failed' => '#fb7185', 'pending' => '#f59e0b', 'unpaid' => '#f59e0b', 'not_due' => '#a1a1aa'];
    @endphp
    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.25rem;" data-payment-state="{{ $payState->value }}">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:0.75rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0;">Fare payment</h3>
            <div style="display:flex;align-items:center;gap:0.5rem;">
                @if($testMode && ($owed || $payState === \App\Enums\PaymentStatus::Paid))
                <span title="Paystack is in test mode: no real money moves" style="font-size:10px;font-weight:700;letter-spacing:0.09em;padding:3px 9px;border-radius:4px;border:1px solid rgba(245,158,11,0.45);color:#f59e0b;">TEST MODE</span>
                @endif
                <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;white-space:nowrap;background:{{ $stateColors[$payState->value] }}1a;color:{{ $stateColors[$payState->value] }};">{{ $payState->label() }}</span>
            </div>
        </div>

        @if($payState === \App\Enums\PaymentStatus::NotDue)
            <p style="font-size:0.8rem;color:#a1a1aa;margin:0;">
                @if(! $paymentsEnabled)
                    Payment is not enabled on this platform, so no payment is due for this ride.
                @else
                    No payment is due for this ride.
                @endif
            </p>
        @elseif($payState === \App\Enums\PaymentStatus::Paid)
            <p style="font-size:0.8rem;color:#a1a1aa;margin:0;">
                Fare paid @if($paidPayment)(<span class="metric-val" style="color:#4ade80;">{{ \App\Services\Ehail\FareCalculator::formatCents($paidPayment->amount_cents) }}</span> on {{ $paidPayment->paid_at?->format('j F Y \a\t g:i A') }})@endif.
            </p>
        @elseif($payState === \App\Enums\PaymentStatus::Waived)
            <p style="font-size:0.8rem;color:#a1a1aa;margin:0;">This fare was waived by a platform operator. Nothing is due.</p>
            @if($isOperator && $ride->fare_waived_reason)
            <p style="font-size:0.75rem;color:#71717a;margin:0.4rem 0 0;">Reason: {{ $ride->fare_waived_reason }}</p>
            @endif
        @elseif($isPassenger)
            <p style="font-size:0.8rem;color:#a1a1aa;margin:0 0 1rem;">
                @if($payState === \App\Enums\PaymentStatus::Pending)
                    You started a payment that is not confirmed yet. Open it again, or check it if you have already paid.
                @elseif($payState === \App\Enums\PaymentStatus::Failed)
                    Your last payment did not go through. You can try again.
                @else
                    Your driver finished this ride. Pay the fare by card through Paystack.
                @endif
            </p>
            @if($paymentsEnabled)
            <div style="display:flex;align-items:center;gap:0.9rem;flex-wrap:wrap;">
                <form method="POST" action="{{ route('rides.pay', $ride) }}">
                    @csrf
                    <button type="submit" class="dot-btn dot-btn-primary">Pay fare <span class="metric-val" style="margin-left:0.35rem;">{{ \App\Services\Ehail\FareCalculator::format($ride->final_fare) }}</span></button>
                </form>
                @if($payState === \App\Enums\PaymentStatus::Pending)
                <a href="{{ route('rides.pay.callback', $ride) }}" style="font-size:0.78rem;color:#38bdf8;text-decoration:none;">I have paid: check now</a>
                @endif
            </div>
            @else
            <p style="font-size:0.78rem;color:#71717a;margin:0;">Online payment is switched off right now. Try again later.</p>
            @endif
        @else
            <p style="font-size:0.8rem;color:#a1a1aa;margin:0;">
                @if($payState === \App\Enums\PaymentStatus::Pending)
                    The passenger has started paying; it is not confirmed yet.
                @else
                    The passenger has not paid this fare yet.
                @endif
            </p>
        @endif

        @can('waive', $ride)
        <form method="POST" action="{{ route('operator.rides.waive', $ride) }}" style="display:flex;flex-direction:column;gap:0.6rem;max-width:460px;margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,0.06);" onsubmit="return confirm('Waive this fare? The passenger will owe nothing.');">
            @csrf
            <div style="font-size:0.8rem;font-weight:600;color:#d4d4d8;">Waive this fare (operators)</div>
            <div>
                <label for="waive-reason" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Why (recorded and audited)</label>
                <input id="waive-reason" type="text" name="reason" maxlength="500" required class="dot-input">
            </div>
            <button type="submit" class="dot-btn dot-btn-ghost" style="align-self:flex-start;">Waive fare</button>
        </form>
        @endcan

        @if(! $isPassenger)
        <p style="font-size:0.72rem;color:#71717a;margin:1rem 0 0;">Payout is not available yet &mdash; fares paid are recorded, settlement to drivers is manual.</p>
        @endif
    </div>
    @endif

    @can('cancel', $ride)
    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.25rem;">
        <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 0.35rem;">
            {{ $viewerRole === \App\Enums\RideActor::Driver ? 'Leave this ride' : 'Cancel this ride' }}
        </h3>
        @if($viewerRole === \App\Enums\RideActor::Driver)
        <p style="font-size:0.75rem;color:#71717a;margin:0 0 1rem;">The ride goes back to the queue for another driver and the passenger is told.</p>
        @else
        <p style="font-size:0.75rem;color:#71717a;margin:0 0 1rem;">{{ $ride->driver_id ? 'Your driver is told straight away.' : 'Nobody has accepted yet.' }}</p>
        @endif
        <form method="POST" action="{{ route('rides.cancel', $ride) }}" style="display:flex;flex-direction:column;gap:0.75rem;max-width:460px;" onsubmit="return confirm('Are you sure?');">
            @csrf
            <div>
                <label for="cancel-reason" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Reason</label>
                <select id="cancel-reason" name="reason" required class="dot-input">
                    <option value="" disabled selected>Choose a reason</option>
                    @foreach($cancelReasons as $reason)
                    <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="cancel-note" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Anything to add (optional)</label>
                <input id="cancel-note" type="text" name="note" maxlength="500" class="dot-input">
            </div>
            <button type="submit" class="dot-btn dot-btn-ghost" style="align-self:flex-start;color:#f87171;">
                {{ $viewerRole === \App\Enums\RideActor::Driver ? 'Leave ride' : 'Cancel ride' }}
            </button>
        </form>
    </div>
    @endcan

    @if($ride->status === 'completed')
    @php
        $myRating = $ride->ratings->firstWhere('rated_by', auth()->id());
        $isOperatorViewer = auth()->user()->isPlatformOperator();
    @endphp
    <div class="dot-card" style="padding:1.5rem;">
        @if($myRating)
            <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 0.75rem;">Your rating</h3>
            <div style="display:flex;align-items:baseline;gap:0.75rem;">
                <span class="metric-val" style="font-size:1.25rem;color:#f59e0b;">{{ $myRating->rating }}/5</span>
                @if($myRating->comment)<span style="font-size:0.8rem;color:#a1a1aa;">{{ $myRating->comment }}</span>@endif
            </div>
        @elsecan('rate', $ride)
            <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 0.25rem;">
                {{ $viewerRole === \App\Enums\RideActor::Passenger ? 'Rate your driver' : 'Rate your passenger' }}
            </h3>
            <p style="font-size:0.75rem;color:#71717a;margin:0 0 1rem;">One rating per ride. It cannot be changed afterwards.</p>
            <form method="POST" action="{{ route('rides.ratings.store', $ride) }}" style="display:flex;flex-direction:column;gap:0.9rem;max-width:460px;">
                @csrf
                <fieldset style="border:0;padding:0;margin:0;">
                    <legend style="font-size:0.75rem;color:#a1a1aa;margin-bottom:0.4rem;padding:0;">Rating, 1 (poor) to 5 (excellent)</legend>
                    <div style="display:flex;gap:0.4rem;">
                        @foreach([1, 2, 3, 4, 5] as $value)
                        <label style="display:flex;align-items:center;gap:0.35rem;padding:0.4rem 0.7rem;border:1px solid rgba(255,255,255,0.12);border-radius:8px;cursor:pointer;font-size:0.85rem;color:#d4d4d8;">
                            <input type="radio" name="rating" value="{{ $value }}" required @checked((int) old('rating') === $value)>
                            <span class="metric-val">{{ $value }}</span>
                        </label>
                        @endforeach
                    </div>
                </fieldset>
                <div>
                    <label for="rating-comment" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Comment (optional, up to 500 characters)</label>
                    <textarea id="rating-comment" name="comment" rows="3" maxlength="500" class="dot-input" style="resize:vertical;">{{ old('comment') }}</textarea>
                </div>
                <button type="submit" class="dot-btn dot-btn-primary" style="align-self:flex-start;">Save rating</button>
            </form>
        @else
            <p style="font-size:0.8rem;color:#71717a;margin:0;">Ratings for this ride can only be left by its passenger and driver.</p>
        @endcan

        @if($isOperatorViewer && $ride->ratings->isNotEmpty())
        <div style="margin-top:1.25rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,0.06);">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">All ratings on this ride (operators only)</div>
            @foreach($ride->ratings as $entry)
            <div style="font-size:0.78rem;color:#a1a1aa;padding:0.2rem 0;">
                {{ $entry->rater?->name ?? 'A user' }} rated the {{ $entry->rated_role }} <span class="metric-val" style="color:#f59e0b;">{{ $entry->rating }}/5</span>@if($entry->comment) — {{ $entry->comment }}@endif
            </div>
            @endforeach
        </div>
        @endif
    </div>
    @endif
</div>

{{--
    Reaching this page already passed RideController::show()'s
    Gate::authorize('view', $ride) (RidePolicy::view() -- true for any
    authenticated user, an intentional platform-wide ops-list gap, see
    RidePolicy's own docblock). The real restriction on who receives live
    updates is the ride.{id} channel's own authorization (passenger/driver
    only, see BroadcastServiceProvider) -- an unauthorized subscription
    from this same page simply never receives anything, so no extra
    server-side check is needed in the script below.
--}}
<script>
    (function () {
        if (! window.Echo) {
            return; // No Reverb credentials configured -- nothing to subscribe to.
        }

        window.Echo.private('ride.{{ $ride->id }}')
            .listen('.status.updated', () => {
                // This is a plain Blade page, not Livewire -- a full reload
                // is the simplest correct way to reflect the new status
                // without hand-building a DOM patcher for a single ops
                // detail page.
                window.location.reload();
            });
    })();
</script>
</x-app-layout>
