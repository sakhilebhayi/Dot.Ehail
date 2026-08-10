<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <div style="margin-bottom:1.5rem;">
        <a href="{{ route('rides.index') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to rides
        </a>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">
                Ride #{{ $ride->id }}
            </h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">Requested {{ $ride->created_at->format('l, F j, Y \a\t g:i A') }}</p>
        </div>
        <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;background:rgba(56,189,248,0.1);color:#38bdf8;">
            {{ ucfirst(str_replace('_', ' ', $ride->status)) }}
        </span>
    </div>

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
            </div>
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.35rem;">Vehicle</div>
                <div style="font-size:12px;color:#d4d4d8;">{{ $ride->vehicle ? "{$ride->vehicle->make} {$ride->vehicle->model} ({$ride->vehicle->plate_number})" : '—' }}</div>
            </div>
            <div>
                <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.35rem;">Fare</div>
                <div class="metric-val" style="font-size:12px;color:#4ade80;font-weight:600;">
                    @if($ride->final_fare)
                        R {{ number_format((float) $ride->final_fare, 2) }}
                    @elseif($ride->estimated_fare)
                        ~R {{ number_format((float) $ride->estimated_fare, 2) }} (estimated)
                    @else
                        —
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="dot-card" style="padding:1.5rem;">
        <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1.25rem;">Rating</h3>
        @if($ride->rating)
            <div style="display:flex;align-items:center;gap:0.5rem;">
                <span style="font-family:'Syne',sans-serif;font-size:1.25rem;font-weight:800;color:#f59e0b;">{{ $ride->rating->rating }}/5</span>
                @if($ride->rating->comment)
                    <span style="font-size:0.8rem;color:#a1a1aa;">"{{ $ride->rating->comment }}"</span>
                @endif
            </div>
        @else
            <div style="text-align:center;padding:2rem 0;">
                <span class="material-symbols-rounded" style="font-size:36px;color:#3f3f46;display:block;margin-bottom:0.75rem;">star_border</span>
                <p style="font-size:0.8rem;color:#52525b;margin:0;">This ride hasn't been rated yet.</p>
            </div>
        @endif
    </div>
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
