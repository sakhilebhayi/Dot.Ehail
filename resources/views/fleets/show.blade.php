<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.3rem;letter-spacing:-0.01em;">
        {{ $fleet->name }}
    </h1>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 2rem;">Driver applications</p>

    @if (session('status'))
        <div class="dot-card" style="padding:0.75rem 1rem;margin-bottom:1.5rem;border:1px solid rgba(34,197,94,0.3);">
            <p style="font-size:0.8rem;color:#4ade80;margin:0;">{{ session('status') }}</p>
        </div>
    @endif

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Pending ({{ $pending->count() }})</h2>
    @forelse ($pending as $profile)
        <div class="dot-card" style="padding:1.25rem;margin-bottom:1rem;">
            <p style="font-size:0.95rem;font-weight:600;color:#f4f4f5;margin:0 0 0.25rem;">{{ $profile->user?->name }}</p>
            <p style="font-size:0.78rem;color:#71717a;margin:0 0 0.75rem;">License {{ $profile->license_number }} · ID {{ $profile->id_number }}</p>
            @foreach ($profile->vehicles as $vehicle)
                <p style="font-size:0.78rem;color:#a1a1aa;margin:0 0 0.75rem;">{{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }} ({{ $vehicle->color }}) — {{ $vehicle->plate_number }}</p>
            @endforeach

            <div style="display:flex;gap:0.5rem;align-items:flex-start;">
                <form method="POST" action="{{ route('fleets.drivers.approve', [$fleet, $profile]) }}">
                    @csrf
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:#22c55e;color:#052e13;font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Approve</button>
                </form>
                <form method="POST" action="{{ route('fleets.drivers.reject', [$fleet, $profile]) }}" style="display:flex;gap:0.4rem;align-items:center;">
                    @csrf
                    <input type="text" name="reason" placeholder="Reason for rejecting" required style="padding:0.4rem;border-radius:6px;font-size:0.78rem;">
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:#ef4444;color:#3f0d0d;font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Reject</button>
                </form>
            </div>
        </div>
    @empty
        <p style="font-size:0.8rem;color:#71717a;">No pending applications.</p>
    @endforelse

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:2rem 0 1rem;">Reviewed</h2>
    @forelse ($reviewed as $profile)
        <div class="dot-card" style="padding:1rem 1.25rem;margin-bottom:0.75rem;display:flex;justify-content:space-between;align-items:center;">
            <div>
                <p style="font-size:0.9rem;color:#f4f4f5;margin:0;">{{ $profile->user?->name }}</p>
                <p style="font-size:0.75rem;color:#71717a;margin:0;">{{ $profile->license_number }}</p>
            </div>
            <span style="font-size:11px;font-weight:600;padding:4px 12px;border-radius:100px;{{ $profile->status === 'approved' ? 'background:rgba(34,197,94,0.1);color:#4ade80;' : ($profile->status === 'rejected' ? 'background:rgba(239,68,68,0.1);color:#f87171;' : 'background:rgba(245,158,11,0.1);color:#f59e0b;') }}">
                {{ ucfirst($profile->status) }}
            </span>
        </div>
    @empty
        <p style="font-size:0.8rem;color:#71717a;">No reviewed applications yet.</p>
    @endforelse
</div>
</x-app-layout>
