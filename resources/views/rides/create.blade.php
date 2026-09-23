<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.3rem;letter-spacing:-0.01em;">
        Request a Ride
    </h1>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 1.5rem;">Coordinates are optional but give you a real fare estimate instead of none at all.</p>

    @if ($errors->any())
        <div class="dot-card" style="padding:1rem;margin-bottom:1.5rem;border:1px solid rgba(239,68,68,0.3);">
            @foreach ($errors->all() as $error)
                <p style="font-size:0.8rem;color:#f87171;margin:0.25rem 0;">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('rides.store') }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Pickup Address</label>
            <input type="text" name="pickup_address" value="{{ old('pickup_address') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Pickup Latitude (optional)</label>
                <input type="number" step="any" name="pickup_lat" value="{{ old('pickup_lat') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Pickup Longitude (optional)</label>
                <input type="number" step="any" name="pickup_lng" value="{{ old('pickup_lng') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
        </div>

        <hr style="border-color:rgba(255,255,255,0.08);">

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Dropoff Address</label>
            <input type="text" name="dropoff_address" value="{{ old('dropoff_address') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Dropoff Latitude (optional)</label>
                <input type="number" step="any" name="dropoff_lat" value="{{ old('dropoff_lat') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Dropoff Longitude (optional)</label>
                <input type="number" step="any" name="dropoff_lng" value="{{ old('dropoff_lng') }}" style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Vehicle Type</label>
            <select name="vehicle_type" required style="width:100%;padding:0.5rem;border-radius:6px;">
                <option value="economy" @selected(old('vehicle_type') === 'economy')>Economy</option>
                <option value="standard" @selected(old('vehicle_type', 'standard') === 'standard')>Standard</option>
                <option value="premium" @selected(old('vehicle_type') === 'premium')>Premium</option>
                <option value="suv" @selected(old('vehicle_type') === 'suv')>SUV</option>
            </select>
        </div>

        <button type="submit" style="margin-top:0.5rem;padding:0.6rem;border-radius:8px;background:#f59e0b;color:#18181b;font-weight:700;border:none;cursor:pointer;">
            Request Ride
        </button>
    </form>
</div>
</x-app-layout>
