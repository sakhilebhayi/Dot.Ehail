<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:1000px;">
    <div style="margin-bottom:1.5rem;">
        <a href="{{ route('dashboard') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to dashboard
        </a>
    </div>

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.2rem;letter-spacing:-0.01em;">
                {{ $driverProfile->user?->name ?? 'Driver' }}
            </h1>
            <p style="font-size:0.78rem;color:#52525b;margin:0;">License {{ $driverProfile->license_number }}</p>
        </div>
        <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;{{ $driverProfile->status === 'approved' ? 'background:rgba(34,197,94,0.1);color:#4ade80;' : ($driverProfile->status === 'suspended' ? 'background:rgba(239,68,68,0.1);color:#f87171;' : 'background:rgba(245,158,11,0.1);color:#f59e0b;') }}">
            {{ ucfirst($driverProfile->status) }}
        </span>
    </div>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.5rem;">
        <div class="dot-card" style="padding:1.25rem;">
            <div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:0.09em;color:#52525b;margin-bottom:0.5rem;">Rating</div>
            <div style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f59e0b;">{{ number_format((float) $driverProfile->rating, 2) }}</div>
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

    @if($driverProfile->vehicles->isNotEmpty())
    <div class="dot-card" style="padding:1.5rem;margin-bottom:1.5rem;">
        <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Vehicles</h3>
        <div style="display:grid;gap:0.5rem;">
            @foreach($driverProfile->vehicles as $vehicle)
            <div style="display:flex;align-items:center;justify-content:between;padding:0.75rem 1rem;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);border-radius:8px;">
                <span style="font-size:12px;color:#d4d4d8;">{{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }} · {{ $vehicle->plate_number }} {{ $vehicle->is_active ? '' : '(inactive)' }}</span>
            </div>
            @endforeach
        </div>
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
