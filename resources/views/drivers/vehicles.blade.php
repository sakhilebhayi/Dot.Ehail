<x-app-layout>
@php
    $active = $vehicles->firstWhere('is_active', true);
    $classLabel = fn ($type) => $type === 'suv' ? 'SUV' : ucfirst($type);
@endphp
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <div style="margin-bottom:1.5rem;">
        <a href="{{ route('drivers.show', $profile) }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to my driver profile
        </a>
    </div>

    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.3rem;letter-spacing:-0.01em;">Vehicles</h1>
    <p style="font-size:0.95rem;color:{{ $active ? '#d4d4d8' : '#fb7185' }};margin:0 0 0.3rem;">
        @if($active)
            Driving {{ $active->year }} {{ $active->make }} {{ $active->model }}, {{ $classLabel($active->type) }}. You see requests for {{ collect($active->servableTiers())->map($classLabel)->join(', ', ' and ') }} rides.
        @else
            No active vehicle. You cannot accept rides until you activate one.
        @endif
    </p>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 2rem;">One vehicle is active at a time. The ride queue only shows requests your active vehicle's class can serve.</p>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-size:0.8rem;">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;font-size:0.8rem;">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    <div class="dot-card" style="overflow:hidden;margin-bottom:2rem;">
        @forelse($vehicles as $vehicle)
        <div style="padding:1.1rem 1.5rem;{{ $loop->last ? '' : 'border-bottom:1px solid rgba(255,255,255,0.06);' }}">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                <div style="min-width:240px;flex:1;">
                    <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                        <span class="metric-val" style="font-size:1rem;color:#f4f4f5;">{{ $vehicle->plate_number }}</span>
                        @if($vehicle->is_active)
                        <span style="font-size:11px;font-weight:600;padding:3px 10px;border-radius:100px;background:rgba(74,222,128,0.1);color:#4ade80;">Active</span>
                        @endif
                    </div>
                    <div style="font-size:0.8rem;color:#a1a1aa;margin-top:0.2rem;">{{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }} · {{ $vehicle->color }} · {{ $vehicle->seats }} seats · {{ $classLabel($vehicle->type) }}</div>
                </div>
                @if($canManage)
                <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                    <a href="{{ route('drivers.vehicles.edit', $vehicle) }}" class="dot-btn dot-btn-ghost">Edit</a>
                    @if($vehicle->is_active)
                    <form method="POST" action="{{ route('drivers.vehicles.deactivate', $vehicle) }}" onsubmit="return confirm('Deactivate this vehicle? You will go offline.');">
                        @csrf
                        <button type="submit" class="dot-btn dot-btn-ghost">Deactivate</button>
                    </form>
                    @else
                    <form method="POST" action="{{ route('drivers.vehicles.activate', $vehicle) }}">
                        @csrf
                        <button type="submit" class="dot-btn dot-btn-primary">Make active</button>
                    </form>
                    @endif
                </div>
                @endif
            </div>

            <div style="margin-top:0.75rem;display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                @forelse($vehicle->documents as $document)
                <a href="{{ route('drivers.documents.show', $document) }}" style="font-size:0.72rem;color:#38bdf8;text-decoration:none;padding:0.25rem 0.6rem;background:rgba(56,189,248,0.08);border-radius:9999px;">{{ $document->original_filename }}</a>
                @empty
                <span style="font-size:0.72rem;color:#71717a;">No inspection record uploaded.</span>
                @endforelse
            </div>
            @if($canManage)
            <form method="POST" action="{{ route('drivers.documents.store') }}" enctype="multipart/form-data" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin-top:0.6rem;">
                @csrf
                <input type="hidden" name="type" value="vehicle_inspection">
                <input type="hidden" name="vehicle_id" value="{{ $vehicle->id }}">
                <label for="doc-{{ $vehicle->id }}" style="font-size:0.72rem;color:#a1a1aa;">Add inspection record</label>
                <input id="doc-{{ $vehicle->id }}" type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required style="font-size:0.75rem;color:#a1a1aa;">
                <button type="submit" class="dot-btn dot-btn-ghost">Upload</button>
            </form>
            @endif
        </div>
        @empty
        <p style="padding:2rem 1.5rem;font-size:0.85rem;color:#71717a;margin:0;">No vehicles yet. Add the one you drive below.</p>
        @endforelse
    </div>

    @if($canManage)
    <div class="dot-card" style="padding:1.5rem;">
        <h2 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Add a vehicle</h2>
        <form method="POST" action="{{ route('drivers.vehicles.store') }}" style="display:flex;flex-direction:column;gap:1rem;">
            @csrf
            @include('drivers._vehicle-fields')
            <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:#a1a1aa;">
                <input type="checkbox" name="activate" value="1" @checked(old('activate'))>
                Make it my active vehicle{{ $active ? ' (switches off '.$active->plate_number.')' : '' }}
            </label>
            <button type="submit" class="dot-btn dot-btn-primary" style="align-self:flex-start;">Add vehicle</button>
        </form>
    </div>
    @else
    <p style="font-size:0.8rem;color:#71717a;">Your driver profile is {{ $profile->status }}, so vehicles cannot be changed.</p>
    @endif
</div>
</x-app-layout>
