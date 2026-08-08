<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:640px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 1.5rem;letter-spacing:-0.01em;">
        Apply to Drive
    </h1>

    @if ($errors->any())
        <div class="dot-card" style="padding:1rem;margin-bottom:1.5rem;border:1px solid rgba(239,68,68,0.3);">
            @foreach ($errors->all() as $error)
                <p style="font-size:0.8rem;color:#f87171;margin:0.25rem 0;">{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('drive.apply.store') }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">License Number</label>
            <input type="text" name="license_number" value="{{ old('license_number') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">ID Number</label>
            <input type="text" name="id_number" value="{{ old('id_number') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Join an existing fleet</label>
            <select name="existing_fleet_id" style="width:100%;padding:0.5rem;border-radius:6px;">
                <option value="">— Select a fleet —</option>
                @foreach ($fleets as $fleet)
                    <option value="{{ $fleet->id }}" @selected(old('existing_fleet_id') == $fleet->id)>{{ $fleet->name }}</option>
                @endforeach
            </select>
        </div>

        <p style="font-size:0.75rem;color:#71717a;text-align:center;">— or —</p>

        <div>
            <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Start my own fleet</label>
            <input type="text" name="new_fleet_name" value="{{ old('new_fleet_name') }}" placeholder="Fleet name" style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <hr style="border-color:rgba(255,255,255,0.08);">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Vehicle Make</label>
                <input type="text" name="vehicle_make" value="{{ old('vehicle_make') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Vehicle Model</label>
                <input type="text" name="vehicle_model" value="{{ old('vehicle_model') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Year</label>
                <input type="number" name="vehicle_year" value="{{ old('vehicle_year') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Color</label>
                <input type="text" name="vehicle_color" value="{{ old('vehicle_color') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Plate Number</label>
                <input type="text" name="vehicle_plate_number" value="{{ old('vehicle_plate_number') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Type</label>
                <select name="vehicle_type" required style="width:100%;padding:0.5rem;border-radius:6px;">
                    <option value="economy">Economy</option>
                    <option value="standard" selected>Standard</option>
                    <option value="premium">Premium</option>
                    <option value="suv">SUV</option>
                </select>
            </div>
        </div>

        <button type="submit" style="margin-top:0.5rem;padding:0.6rem;border-radius:8px;background:#f59e0b;color:#18181b;font-weight:700;border:none;cursor:pointer;">
            Submit Application
        </button>
    </form>
</div>
</x-app-layout>
