<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:900px;">
    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 0.3rem;letter-spacing:-0.01em;">Fleet review</h1>
    <p style="font-size:0.78rem;color:#71717a;margin:0 0 2rem;">A fleet created through a driver application stays pending until you approve it.</p>

    @if (session('status'))
        <div class="dot-card" style="padding:0.75rem 1rem;margin-bottom:1.5rem;border:1px solid rgba(34,197,94,0.3);">
            <p style="font-size:0.8rem;color:#4ade80;margin:0;">{{ session('status') }}</p>
        </div>
    @endif

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Pending ({{ $pending->count() }})</h2>
    @forelse ($pending as $fleet)
        <div class="dot-card" style="padding:1.25rem;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div>
                <p style="font-size:0.95rem;font-weight:600;color:#f4f4f5;margin:0 0 0.25rem;">{{ $fleet->name }}</p>
                <p style="font-size:0.75rem;color:#71717a;margin:0;">Owner {{ $fleet->owner?->name }} · created {{ $fleet->created_at->diffForHumans() }}</p>
            </div>
            <div style="display:flex;gap:0.5rem;align-items:center;">
                <a href="{{ route('fleets.show', $fleet) }}" style="font-size:0.78rem;color:#38bdf8;text-decoration:none;">Review applications</a>
                <form method="POST" action="{{ route('operator.fleets.approve', $fleet) }}">
                    @csrf
                    <button type="submit" style="padding:0.4rem 1rem;border-radius:6px;background:#22c55e;color:#052e13;font-weight:700;border:none;font-size:0.78rem;cursor:pointer;">Approve fleet</button>
                </form>
            </div>
        </div>
    @empty
        <p style="font-size:0.8rem;color:#71717a;">No fleets waiting for review.</p>
    @endforelse

    <h2 style="font-size:1rem;font-weight:700;color:#f4f4f5;margin:2rem 0 1rem;">Approved ({{ $approved->count() }})</h2>
    @foreach ($approved as $fleet)
        <div class="dot-card" style="padding:1rem 1.25rem;margin-bottom:0.75rem;">
            <p style="font-size:0.9rem;color:#f4f4f5;margin:0;">{{ $fleet->name }}</p>
            <p style="font-size:0.75rem;color:#71717a;margin:0;">Owner {{ $fleet->owner?->name }}</p>
        </div>
    @endforeach
</div>
</x-app-layout>
