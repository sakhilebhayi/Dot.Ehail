<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">Available Rides</h1>
            <p style="font-size:0.8rem;color:#71717a;margin:0;">Open requests any driver can claim. First to accept wins.</p>
        </div>
        <a href="{{ route('rides.index') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to rides
        </a>
    </div>

    @if ($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;font-size:0.8rem;">
        @foreach ($errors->all() as $error)
        <div>{{ $error }}</div>
        @endforeach
    </div>
    @endif

    @unless($canAcceptRides)
    <div class="dot-card" style="padding:1.5rem;text-align:center;">
        @if(! $profile)
            <p style="font-size:0.85rem;color:#a1a1aa;margin:0 0 0.75rem;">You're not registered as a driver yet.</p>
            <a href="{{ route('drive.apply') }}" style="font-size:0.8rem;color:#f59e0b;font-weight:600;text-decoration:none;">Apply to drive →</a>
        @elseif($profile->status !== 'approved')
            <p style="font-size:0.85rem;color:#a1a1aa;margin:0;">Your driver application is {{ $profile->status }}. You'll be able to accept rides once approved.</p>
        @elseif(! $profile->is_online)
            <p style="font-size:0.85rem;color:#a1a1aa;margin:0;">You're offline. Go online to see and accept ride requests.</p>
        @else
            <p style="font-size:0.85rem;color:#a1a1aa;margin:0;">You need an active vehicle registered before you can accept rides.</p>
        @endif
    </div>
    @else
    <div class="dot-card" style="overflow:hidden;">
        @if($rides->isEmpty())
        <div style="padding:3.5rem 1rem;text-align:center;color:#71717a;">
            <span class="material-symbols-rounded" style="font-size:48px;display:block;margin-bottom:0.75rem;opacity:0.3;">local_taxi</span>
            <p style="font-size:0.85rem;margin:0;">No open ride requests right now.</p>
        </div>
        @else
        @foreach($rides as $ride)
        <div style="padding:1.1rem 1.5rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div style="flex:1;min-width:220px;">
                <div style="font-size:0.8rem;color:#f4f4f5;font-weight:600;">{{ $ride->pickup_address }}</div>
                <div style="font-size:0.75rem;color:#71717a;">→ {{ $ride->dropoff_address }}</div>
                <div style="font-size:0.68rem;color:#52525b;margin-top:0.25rem;">
                    {{ ucfirst($ride->vehicle_type) }} · Requested {{ $ride->created_at->diffForHumans() }}
                    @if($ride->estimated_fare) · ~R{{ number_format((float) $ride->estimated_fare, 2) }} @endif
                </div>
            </div>
            <form method="POST" action="{{ route('rides.accept', $ride) }}">
                @csrf
                <button type="submit" class="dot-btn dot-btn-primary">Accept</button>
            </form>
        </div>
        @endforeach
        @endif
    </div>
    @endunless

</div>
</x-app-layout>
