<x-app-layout>
@php
    $inputStyle = 'width:100%;padding:0.45rem 0.6rem;border-radius:6px;font-size:0.8rem;';
    $labelStyle = 'display:block;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#71717a;margin-bottom:0.3rem;';
    $reviewCount = $needsReview->count();
    $waitMinutes = $oldestOpenRequest ? (int) \Carbon\Carbon::parse($oldestOpenRequest)->diffInMinutes(now(), true) : null;
@endphp
<div style="padding:2rem 2.5rem 3rem;max-width:980px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.3rem;letter-spacing:-0.01em;">Ride operations</h1>

    {{-- The answer to "does anything need me?", in words, before any figure. --}}
    <p style="font-size:1.05rem;color:{{ $reviewCount > 0 ? '#fb7185' : '#a1a1aa' }};margin:0 0 0.35rem;" data-state="{{ $reviewCount > 0 ? 'needs-review' : 'clear' }}">
        @if ($reviewCount > 0)
            {{ $reviewCount }} {{ \Illuminate\Support\Str::plural('trip', $reviewCount) }} {{ $reviewCount === 1 ? 'needs' : 'need' }} a decision.
        @else
            Nothing needs review.
        @endif
    </p>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 2rem;">
        Trips still in progress after {{ config('ehail.stuck_ride_hours') }} hours are flagged here and never completed automatically.
        Requests nobody accepts within {{ config('ehail.ride_expiry_minutes') }} minutes expire on their own.
    </p>

    @if (session('status'))
        <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-size:0.8rem;">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;font-size:0.8rem;">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </div>
    @endif

    {{-- Needs review --}}
    @if ($reviewCount > 0)
    <h2 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;margin:0 0 0.75rem;text-transform:uppercase;letter-spacing:0.08em;">Needs review</h2>
    @foreach ($needsReview as $ride)
        <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div style="min-width:240px;flex:1;">
                    <a href="{{ route('rides.show', $ride) }}" style="font-size:0.9rem;font-weight:600;color:#f4f4f5;text-decoration:none;">Ride #{{ $ride->id }} · {{ $ride->pickup_address }} → {{ $ride->dropoff_address }}</a>
                    <p style="font-size:0.75rem;color:#71717a;margin:0.3rem 0 0;">
                        Driver {{ $ride->driver?->name ?? '—' }} · passenger {{ $ride->passenger?->name ?? '—' }}
                        · flagged {{ $ride->needs_review_at?->diffForHumans() ?? 'earlier' }}
                    </p>
                    <p style="font-size:0.75rem;color:#a1a1aa;margin:0.2rem 0 0;">
                        @if ($ride->estimated_fare !== null)
                            Estimated fare {{ \App\Services\Ehail\FareCalculator::format($ride->estimated_fare) }}
                        @else
                            No fare estimate on this ride.
                        @endif
                    </p>
                </div>
                <x-ride-status :status="$ride->status" />
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.5rem;margin-top:1.1rem;padding-top:1.1rem;border-top:1px solid rgba(255,255,255,0.06);">
                <form method="POST" action="{{ route('operator.rides.complete', $ride) }}" style="display:flex;flex-direction:column;gap:0.6rem;">
                    @csrf
                    <div style="font-size:0.8rem;font-weight:600;color:#d4d4d8;">Enter the fare and complete</div>
                    <div>
                        <label for="fare-{{ $ride->id }}" style="{{ $labelStyle }}">Final fare ({{ config('ehail.currency.code') }})</label>
                        <input id="fare-{{ $ride->id }}" class="dot-input" type="number" name="final_fare" step="0.01" min="0" required style="{{ $inputStyle }}">
                    </div>
                    <div>
                        <label for="reason-{{ $ride->id }}" style="{{ $labelStyle }}">Why this fare</label>
                        <input id="reason-{{ $ride->id }}" class="dot-input" type="text" name="reason" maxlength="500" required placeholder="What you confirmed with the driver or passenger" style="{{ $inputStyle }}">
                    </div>
                    <button type="submit" class="dot-btn dot-btn-primary" style="align-self:flex-start;">Complete with this fare</button>
                </form>
                <form method="POST" action="{{ route('operator.rides.cancel', $ride) }}" style="display:flex;flex-direction:column;gap:0.6rem;" onsubmit="return confirm('Cancel this trip? Both parties are notified.');">
                    @csrf
                    <div style="font-size:0.8rem;font-weight:600;color:#d4d4d8;">Or cancel the trip</div>
                    <div>
                        <label for="cancel-{{ $ride->id }}" style="{{ $labelStyle }}">Reason for cancelling</label>
                        <input id="cancel-{{ $ride->id }}" class="dot-input" type="text" name="reason" maxlength="500" required placeholder="Recorded on the ride and sent to both parties" style="{{ $inputStyle }}">
                    </div>
                    <button type="submit" class="dot-btn dot-btn-ghost" style="align-self:flex-start;color:#f87171;">Cancel trip</button>
                </form>
            </div>
        </div>
    @endforeach
    @endif

    {{-- The queue, as a ledger: label left, real figure right. --}}
    <h2 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;margin:{{ $reviewCount > 0 ? '2rem' : '0' }} 0 0.75rem;text-transform:uppercase;letter-spacing:0.08em;">Queue</h2>
    <div class="dot-card" style="overflow:hidden;margin-bottom:2rem;">
        @php
            $rows = [
                ['key' => 'open-requests', 'label' => 'Open ride requests', 'value' => number_format($openRequests), 'note' => $waitMinutes !== null ? 'oldest waiting '.$waitMinutes.' min' : 'none waiting', 'href' => null],
                ['key' => 'pending-fleets', 'label' => 'Fleets awaiting approval', 'value' => number_format($pendingFleets), 'note' => null, 'href' => route('operator.fleets.index')],
                ['key' => 'pending-applications', 'label' => 'Driver applications pending', 'value' => number_format($pendingApplications), 'note' => null, 'href' => route('operator.fleets.index')],
                ['key' => 'expired-week', 'label' => 'Requests expired in the last 7 days', 'value' => number_format($expiredLastWeek), 'note' => null, 'href' => null],
                ['key' => 'fare-total', 'label' => 'Estimated fare total, not collected', 'value' => \App\Services\Ehail\FareCalculator::format($completedFareTotal), 'note' => 'over '.number_format($completedCount).' completed '.\Illuminate\Support\Str::plural('ride', $completedCount).' with a recorded fare', 'href' => null],
                ['key' => 'fares-collected', 'label' => 'Fares collected through Paystack', 'value' => \App\Services\Ehail\FareCalculator::formatCents($collectedCents), 'note' => 'over '.number_format($collectedCount).' confirmed '.\Illuminate\Support\Str::plural('payment', $collectedCount).($testMode ? ' (TEST MODE, not real money)' : ''), 'href' => null],
            ];
        @endphp
        @foreach ($rows as $row)
            <div style="display:flex;align-items:baseline;justify-content:space-between;gap:1rem;padding:0.85rem 1.5rem;{{ $loop->last ? '' : 'border-bottom:1px solid rgba(255,255,255,0.06);' }}flex-wrap:wrap;">
                <div>
                    @if ($row['href'])
                        <a href="{{ $row['href'] }}" style="font-size:0.85rem;color:#38bdf8;text-decoration:none;">{{ $row['label'] }}</a>
                    @else
                        <span style="font-size:0.85rem;color:#d4d4d8;">{{ $row['label'] }}</span>
                    @endif
                    @if ($row['note'])<span style="font-size:0.72rem;color:#71717a;margin-left:0.5rem;">{{ $row['note'] }}</span>@endif
                </div>
                <span class="metric-val" style="font-size:1rem;color:#f4f4f5;" data-figure="{{ $row['key'] }}">{{ $row['value'] }}</span>
            </div>
        @endforeach
    </div>

    {{-- Payments --}}
    <h2 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;margin:0 0 0.75rem;text-transform:uppercase;letter-spacing:0.08em;">Fare payments</h2>
    @if (! $paymentsEnabled)
        <div class="dot-card" style="padding:1rem 1.5rem;margin-bottom:1rem;">
            <p style="font-size:0.8rem;color:#a1a1aa;margin:0;">{{ $paymentNotice }} Rides completed now owe nothing, and the sections below only show what was recorded earlier.</p>
        </div>
    @endif
    <p style="font-size:0.75rem;color:#71717a;margin:0 0 1rem;">
        Passengers pay the platform's Paystack account. Payout to drivers is not available yet: fares paid are recorded, settlement to drivers is manual.
    </p>

    <h3 style="font-size:0.8rem;font-weight:600;color:#d4d4d8;margin:0 0 0.5rem;">Unpaid fares <span style="font-weight:400;color:#71717a;">completed more than {{ $alertHours }} {{ \Illuminate\Support\Str::plural('hour', $alertHours) }} ago</span></h3>
    <div class="dot-card" style="overflow:hidden;margin-bottom:1.5rem;" data-section="unpaid-fares">
        @forelse ($unpaidFares as $ride)
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;padding:0.85rem 1.5rem;{{ $loop->last ? '' : 'border-bottom:1px solid rgba(255,255,255,0.06);' }}flex-wrap:wrap;">
                <div style="min-width:240px;flex:1;">
                    <a href="{{ route('rides.show', $ride) }}" style="font-size:0.82rem;color:#f4f4f5;text-decoration:none;">#{{ $ride->id }} · {{ $ride->pickup_address }} → {{ $ride->dropoff_address }}</a>
                    <div style="font-size:0.72rem;color:#71717a;">
                        {{ $ride->passenger?->name ?? '—' }} · <span class="metric-val">{{ \App\Services\Ehail\FareCalculator::format($ride->final_fare) }}</span>
                        · {{ $ride->paymentStatusEnum()->label() }} · completed {{ $ride->completed_at?->diffForHumans() ?? 'earlier' }}
                    </div>
                </div>
                <form method="POST" action="{{ route('operator.rides.waive', $ride) }}" style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap;" onsubmit="return confirm('Waive this fare? The passenger will owe nothing.');">
                    @csrf
                    <div>
                        <label for="waive-{{ $ride->id }}" style="{{ $labelStyle }}">Waive: why</label>
                        <input id="waive-{{ $ride->id }}" class="dot-input" type="text" name="reason" maxlength="500" required style="{{ $inputStyle }}width:220px;">
                    </div>
                    <button type="submit" class="dot-btn dot-btn-ghost">Waive fare</button>
                </form>
            </div>
        @empty
            <p style="padding:1.25rem 1.5rem;font-size:0.8rem;color:#71717a;margin:0;">No fare has been unpaid for that long.</p>
        @endforelse
    </div>

    <h3 style="font-size:0.8rem;font-weight:600;color:#d4d4d8;margin:0 0 0.5rem;">Unmatched payments</h3>
    <div class="dot-card" style="overflow:hidden;margin-bottom:1.5rem;" data-section="unmatched-payments">
        @forelse ($unmatchedPayments as $payment)
            <div style="padding:0.85rem 1.5rem;{{ $loop->last ? '' : 'border-bottom:1px solid rgba(255,255,255,0.06);' }}">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                    <div style="min-width:240px;flex:1;">
                        <span style="font-size:0.82rem;color:#fb7185;">{{ $payment->reasonLabel() }}</span>
                        <div style="font-size:0.72rem;color:#71717a;">
                            <span class="metric-val">{{ \App\Services\Ehail\FareCalculator::formatCents($payment->amount_cents) }}</span> {{ $payment->currency }}
                            · reference {{ $payment->reference }}
                            @if ($payment->ride) · <a href="{{ route('rides.show', $payment->ride) }}" style="color:#38bdf8;text-decoration:none;">ride #{{ $payment->ride->id }}</a>@endif
                            · {{ $payment->updated_at?->diffForHumans() }}
                        </div>
                        <div style="font-size:0.72rem;color:#71717a;margin-top:0.2rem;">The payer may have been charged. Check it on the Paystack dashboard, refund or reconcile there, then record what you did.</div>
                    </div>
                    <form method="POST" action="{{ route('operator.payments.review', $payment) }}" style="display:flex;gap:0.5rem;align-items:flex-end;flex-wrap:wrap;">
                        @csrf
                        <div>
                            <label for="review-{{ $payment->id }}" style="{{ $labelStyle }}">What you did</label>
                            <input id="review-{{ $payment->id }}" class="dot-input" type="text" name="note" maxlength="500" required style="{{ $inputStyle }}width:220px;">
                        </div>
                        <button type="submit" class="dot-btn dot-btn-ghost">Mark reviewed</button>
                    </form>
                </div>
            </div>
        @empty
            <p style="padding:1.25rem 1.5rem;font-size:0.8rem;color:#71717a;margin:0;">No payment is waiting for review.</p>
        @endforelse
    </div>

    <h3 style="font-size:0.8rem;font-weight:600;color:#d4d4d8;margin:0 0 0.5rem;">Waived fares <span style="font-weight:400;color:#71717a;">latest {{ $waivedFares->count() }}</span></h3>
    <div class="dot-card" style="overflow:hidden;margin-bottom:2rem;" data-section="waived-fares">
        @forelse ($waivedFares as $ride)
            <div style="padding:0.85rem 1.5rem;{{ $loop->last ? '' : 'border-bottom:1px solid rgba(255,255,255,0.06);' }}">
                <a href="{{ route('rides.show', $ride) }}" style="font-size:0.82rem;color:#f4f4f5;text-decoration:none;">#{{ $ride->id }} · <span class="metric-val">{{ \App\Services\Ehail\FareCalculator::format($ride->final_fare) }}</span></a>
                <div style="font-size:0.72rem;color:#71717a;">{{ $ride->fare_waived_reason }} · by {{ $ride->waivedBy?->name ?? 'an operator' }} {{ $ride->fare_waived_at?->diffForHumans() }}</div>
            </div>
        @empty
            <p style="padding:1.25rem 1.5rem;font-size:0.8rem;color:#71717a;margin:0;">No fare has been waived.</p>
        @endforelse
    </div>

    {{-- Expired requests --}}
    <h2 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;margin:0 0 0.75rem;text-transform:uppercase;letter-spacing:0.08em;">Recently expired requests</h2>
    <div class="dot-card" style="overflow:hidden;">
        @forelse ($expired as $ride)
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:0.85rem 1.5rem;{{ $loop->last ? '' : 'border-bottom:1px solid rgba(255,255,255,0.06);' }}flex-wrap:wrap;">
                <div style="min-width:240px;flex:1;">
                    <a href="{{ route('rides.show', $ride) }}" style="font-size:0.82rem;color:#f4f4f5;text-decoration:none;">#{{ $ride->id }} · {{ $ride->pickup_address }} → {{ $ride->dropoff_address }}</a>
                    <div style="font-size:0.72rem;color:#71717a;">{{ $ride->passenger?->name ?? '—' }} · {{ ucfirst($ride->vehicle_type) }} · expired {{ $ride->expired_at?->diffForHumans() ?? 'earlier' }}@if ($ride->reassignment_count > 0) · handed back {{ $ride->reassignment_count }}× @endif</div>
                </div>
                <x-ride-status :status="$ride->status" />
            </div>
        @empty
            <p style="padding:1.5rem;font-size:0.8rem;color:#71717a;margin:0;">No requests have expired.</p>
        @endforelse
    </div>
</div>
</x-app-layout>
