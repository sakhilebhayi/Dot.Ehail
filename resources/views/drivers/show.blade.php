<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:1000px;">
    <div style="margin-bottom:1.5rem;">
        <a href="{{ route('dashboard') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to dashboard
        </a>
    </div>

    @if (session('status'))
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);color:#4ade80;font-size:0.8rem;">
        {{ session('status') }}
    </div>
    @endif

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">
                {{ $driverProfile->user?->name ?? 'Driver' }}
            </h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">License {{ $driverProfile->license_number }}</p>
        </div>
        <div style="display:flex;align-items:center;gap:0.6rem;">
            @can('update', $driverProfile)
            <form method="POST" action="{{ route('drivers.toggle-online', $driverProfile) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="dot-btn {{ $driverProfile->is_online ? 'dot-btn-ghost' : 'dot-btn-primary' }}">
                    {{ $driverProfile->is_online ? 'Go Offline' : 'Go Online' }}
                </button>
            </form>
            @endcan
            <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;{{ $driverProfile->status === 'approved' ? 'background:rgba(34,197,94,0.1);color:#4ade80;' : ($driverProfile->status === 'suspended' ? 'background:rgba(239,68,68,0.1);color:#f87171;' : 'background:rgba(245,158,11,0.1);color:#f59e0b;') }}">
                {{ ucfirst($driverProfile->status) }}
            </span>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">
        <div class="dot-card" style="padding:1.25rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">Rating</div>
            @if($ratingCount > 0)
            <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f59e0b;" data-figure="rating-average">{{ number_format((float) $driverProfile->rating, 2) }}</div>
            <div style="font-size:0.72rem;color:#71717a;margin-top:0.25rem;">out of 5 from <span data-figure="rating-count">{{ $ratingCount }}</span> {{ \Illuminate\Support\Str::plural('rating', $ratingCount) }}</div>
            @else
            <div style="font-family:'Syne',sans-serif;font-size:1.1rem;font-weight:700;color:#a1a1aa;line-height:2.25rem;">No ratings yet</div>
            @endif
        </div>
        <div class="dot-card" style="padding:1.25rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">Total Rides</div>
            <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;">{{ number_format($driverProfile->total_rides) }}</div>
        </div>
        <div class="dot-card" style="padding:1.25rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">Status</div>
            <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:{{ $driverProfile->is_online ? '#4ade80' : '#71717a' }};">{{ $driverProfile->is_online ? 'Online' : 'Offline' }}</div>
        </div>
    </div>

    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Documents</h3>

        @if($driverProfile->documents->isEmpty())
        <p style="font-size:0.8rem;color:#71717a;margin:0 0 1rem;">No documents uploaded yet.</p>
        @else
        <div style="display:grid;gap:0.5rem;margin-bottom:1rem;">
            @foreach($driverProfile->documents as $document)
            <div style="display:flex;align-items:center;justify-content:space-between;padding:0.75rem 1rem;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
                <a href="{{ route('drivers.documents.show', $document) }}" style="font-size:12px;color:#38bdf8;text-decoration:none;">
                    {{ ucfirst(str_replace('_', ' ', $document->type)) }} — {{ $document->original_filename }}
                </a>
                @if(auth()->id() === $driverProfile->user_id)
                <form method="POST" action="{{ route('drivers.documents.destroy', $document) }}" onsubmit="return confirm('Delete this document?');">
                    @csrf @method('DELETE')
                    <button type="submit" style="background:none;border:none;color:#f87171;font-size:11px;cursor:pointer;">Delete</button>
                </form>
                @endif
            </div>
            @endforeach
        </div>
        @endif

        @if(auth()->id() === $driverProfile->user_id)
        <form method="POST" action="{{ route('drivers.documents.store') }}" enctype="multipart/form-data" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            @csrf
            <select name="type" required style="padding:0.4rem;border-radius:6px;font-size:0.75rem;">
                <option value="license">Driver's License</option>
                <option value="id">ID Document</option>
                <option value="vehicle_inspection">Vehicle Inspection Record</option>
            </select>
            <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" required style="font-size:0.78rem;color:#a1a1aa;">
            <button type="submit" class="dot-btn dot-btn-primary">Upload</button>
        </form>
        @endif
    </div>

    @if($driverProfile->vehicles->isNotEmpty())
    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;margin:0 0 1rem;">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0;">Vehicles</h3>
            @if(auth()->id() === $driverProfile->user_id)
            <a href="{{ route('drivers.vehicles.index') }}" style="font-size:0.78rem;color:#38bdf8;text-decoration:none;">Manage vehicles</a>
            @endif
        </div>
        <div style="display:grid;gap:0.5rem;">
            @foreach($driverProfile->vehicles as $vehicle)
            <div style="display:flex;align-items:center;justify-content:between;padding:0.75rem 1rem;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
                <span style="font-size:12px;color:#d4d4d8;">{{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }} · {{ $vehicle->plate_number }} {{ $vehicle->is_active ? '' : '(inactive)' }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($recentRatings->isNotEmpty())
    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Recent comments</h3>
        @foreach($recentRatings as $entry)
        <div style="padding:0.75rem 0;{{ $loop->last ? '' : 'border-bottom:1px solid rgba(255,255,255,0.06);' }}">
            <div style="display:flex;align-items:baseline;gap:0.6rem;margin-bottom:0.2rem;">
                <span class="metric-val" style="font-size:0.85rem;color:#f59e0b;">{{ $entry->rating }}/5</span>
                <span style="font-size:0.72rem;color:#71717a;">
                    {{ $canSeeRaters ? ($entry->rater?->name ?? 'A passenger') : 'A passenger' }} · {{ $entry->created_at->diffForHumans() }}
                </span>
            </div>
            <div style="font-size:0.82rem;color:#d4d4d8;">{{ $entry->comment }}</div>
        </div>
        @endforeach
    </div>
    @endif

    <div class="dot-card" style="overflow:hidden;">
        <div style="padding:1.25rem 1.5rem;border-bottom:1px solid rgba(255,255,255,0.06);">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;margin:0;text-transform:uppercase;letter-spacing:0.08em;">Ride History</h3>
        </div>
        @if($rides->isEmpty())
        <div style="padding:3rem;text-align:center;color:#71717a;">
            <span class="material-symbols-rounded" style="font-size:48px;display:block;margin-bottom:0.75rem;opacity:0.3;">local_taxi</span>
            <p style="font-size:0.85rem;margin:0;">No rides recorded for this driver yet.</p>
        </div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <tbody>
                    @foreach($rides as $ride)
                    <tr onclick="window.location='{{ route('rides.show', $ride) }}'" style="cursor:pointer;border-bottom:1px solid rgba(67,70,86,0.1);">
                        <td style="padding:0.85rem 1.5rem;font-size:0.75rem;color:#71717a;">#{{ $ride->id }}</td>
                        <td style="padding:0.85rem 1rem;font-size:0.75rem;color:#f4f4f5;">{{ $ride->pickup_address }} → {{ $ride->dropoff_address }}</td>
                        <td style="padding:0.85rem 1rem;font-size:0.72rem;color:#38bdf8;">{{ ucfirst(str_replace('_', ' ', $ride->status)) }}</td>
                        <td style="padding:0.85rem 1.5rem;text-align:right;font-size:0.7rem;color:#71717a;">{{ $ride->created_at->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
</x-app-layout>
