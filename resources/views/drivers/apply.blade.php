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

    <form method="POST" action="{{ route('drive.apply.store') }}" enctype="multipart/form-data" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf

        <div>
            <label for="drivers-apply-license-number" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">License Number</label>
            <input id="drivers-apply-license-number" type="text" name="license_number" value="{{ old('license_number') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label for="drivers-apply-id-number" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">ID Number</label>
            <input id="drivers-apply-id-number" type="text" name="id_number" value="{{ old('id_number') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <div>
            <label for="drivers-apply-existing-fleet-id" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Join an existing fleet</label>
            <select id="drivers-apply-existing-fleet-id" name="existing_fleet_id" style="width:100%;padding:0.5rem;border-radius:6px;">
                <option value="">— Select a fleet —</option>
                @foreach ($fleets as $fleet)
                    <option value="{{ $fleet->id }}" @selected(old('existing_fleet_id') == $fleet->id)>{{ $fleet->name }}</option>
                @endforeach
            </select>
        </div>

        <p style="font-size:0.75rem;color:#71717a;text-align:center;">— or —</p>

        <div>
            <label for="drivers-apply-new-fleet-name" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Start my own fleet</label>
            <input id="drivers-apply-new-fleet-name" type="text" name="new_fleet_name" value="{{ old('new_fleet_name') }}" placeholder="Fleet name" style="width:100%;padding:0.5rem;border-radius:6px;">
        </div>

        <hr style="border-color:rgba(255,255,255,0.08);">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
            <div>
                <label for="drivers-apply-vehicle-make" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Vehicle Make</label>
                <input id="drivers-apply-vehicle-make" type="text" name="vehicle_make" value="{{ old('vehicle_make') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="drivers-apply-vehicle-model" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Vehicle Model</label>
                <input id="drivers-apply-vehicle-model" type="text" name="vehicle_model" value="{{ old('vehicle_model') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="drivers-apply-vehicle-year" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Year</label>
                <input id="drivers-apply-vehicle-year" type="number" name="vehicle_year" value="{{ old('vehicle_year') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="drivers-apply-vehicle-color" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Color</label>
                <input id="drivers-apply-vehicle-color" type="text" name="vehicle_color" value="{{ old('vehicle_color') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="drivers-apply-vehicle-plate-number" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Plate Number</label>
                <input id="drivers-apply-vehicle-plate-number" type="text" name="vehicle_plate_number" value="{{ old('vehicle_plate_number') }}" required style="width:100%;padding:0.5rem;border-radius:6px;">
            </div>
            <div>
                <label for="drivers-apply-vehicle-type" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Type</label>
                <select id="drivers-apply-vehicle-type" name="vehicle_type" required style="width:100%;padding:0.5rem;border-radius:6px;">
                    <option value="economy">Economy</option>
                    <option value="standard" selected>Standard</option>
                    <option value="premium">Premium</option>
                    <option value="suv">SUV</option>
                </select>
            </div>
        </div>

        <hr style="border-color:rgba(255,255,255,0.08);">

        <p style="font-size:0.75rem;color:#a1a1aa;margin:0;">Documents (optional — you can also add these later from your driver profile)</p>
        <div>
            <label for="drivers-apply-license-document" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Driver's License</label>
            <input id="drivers-apply-license-document" type="file" name="license_document" accept=".pdf,.jpg,.jpeg,.png" style="width:100%;font-size:0.8rem;color:#a1a1aa;">
        </div>
        <div>
            <label for="drivers-apply-id-document" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">ID Document</label>
            <input id="drivers-apply-id-document" type="file" name="id_document" accept=".pdf,.jpg,.jpeg,.png" style="width:100%;font-size:0.8rem;color:#a1a1aa;">
        </div>
        <div>
            <label for="drivers-apply-vehicle-inspection-document" style="display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;">Vehicle Inspection Record</label>
            <input id="drivers-apply-vehicle-inspection-document" type="file" name="vehicle_inspection_document" accept=".pdf,.jpg,.jpeg,.png" style="width:100%;font-size:0.8rem;color:#a1a1aa;">
        </div>

        <button type="submit" style="margin-top:0.5rem;padding:0.6rem;border-radius:8px;background:#f59e0b;color:#18181b;font-weight:700;border:none;cursor:pointer;">
            Submit Application
        </button>
    </form>
</div>
</x-app-layout>
