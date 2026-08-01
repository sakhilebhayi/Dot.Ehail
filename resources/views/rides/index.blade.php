<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:1400px;">

    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0 0 0.25rem;">Rides</h1>
            <p style="font-size:0.8rem;color:#71717a;margin:0;">Search and browse every ride recorded on the platform.</p>
        </div>
        <a href="{{ route('dashboard') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to dashboard
        </a>
    </div>

    <form method="GET" action="{{ route('rides.index') }}" style="margin-bottom:1.5rem;display:flex;gap:0.6rem;max-width:480px;">
        <div style="position:relative;flex:1;">
            <span class="material-symbols-rounded" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);font-size:17px;color:#52525b;">search</span>
            <input
                type="text"
                name="q"
                value="{{ $search }}"
                placeholder="Search pickup, dropoff, driver, or passenger…"
                class="dot-input"
                style="padding-left:34px;"
            >
        </div>
        <button type="submit" class="dot-btn dot-btn-primary">Search</button>
        @if($search !== '')
            <a href="{{ route('rides.index') }}" class="dot-btn dot-btn-ghost">Clear</a>
        @endif
    </form>

    <div class="dot-card" style="overflow:hidden;">
        @if($rides->isEmpty())
        <div style="padding:3.5rem 1rem;text-align:center;color:#71717a;">
            <span class="material-symbols-rounded" style="font-size:48px;display:block;margin-bottom:0.75rem;opacity:0.3;">local_taxi</span>
            @if($search !== '')
                <p style="font-size:0.85rem;margin:0;">No rides match "{{ $search }}".</p>
            @else
                <p style="font-size:0.85rem;margin:0;">No rides recorded yet.</p>
            @endif
        </div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
                        <th style="padding:0.65rem 1.5rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">#</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Route</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Driver</th>
                        <th style="padding:0.65rem 1rem;text-align:left;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Status</th>
                        <th style="padding:0.65rem 1.5rem;text-align:right;font-size:0.65rem;font-weight:700;color:#71717a;text-transform:uppercase;letter-spacing:0.12em;">Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rides as $ride)
                    <tr onclick="window.location='{{ route('rides.show', $ride) }}'" style="cursor:pointer;border-bottom:1px solid rgba(67,70,86,0.1);transition:background 0.15s;" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background='transparent'">
                        <td style="padding:0.85rem 1.5rem;font-size:0.75rem;font-weight:600;color:#71717a;font-family:'Syne',sans-serif;">#{{ $ride->id }}</td>
                        <td style="padding:0.85rem 1rem;max-width:260px;">
                            <div style="font-size:0.75rem;color:#f4f4f5;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $ride->pickup_address }}</div>
                            <div style="font-size:0.7rem;color:#71717a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">→ {{ $ride->dropoff_address }}</div>
                        </td>
                        <td style="padding:0.85rem 1rem;font-size:0.75rem;color:#f4f4f5;">{{ $ride->driver->name ?? 'Unassigned' }}</td>
                        <td style="padding:0.85rem 1rem;">
                            <span style="display:inline-flex;align-items:center;padding:0.25rem 0.6rem;background:rgba(56,189,248,0.1);border-radius:9999px;font-size:0.65rem;font-weight:700;color:#38bdf8;font-family:'Syne',sans-serif;">
                                {{ ucfirst(str_replace('_', ' ', $ride->status)) }}
                            </span>
                        </td>
                        <td style="padding:0.85rem 1.5rem;text-align:right;font-size:0.7rem;color:#71717a;white-space:nowrap;">{{ $ride->created_at->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding:1rem 1.5rem;border-top:1px solid rgba(255,255,255,0.06);">
            {{ $rides->links() }}
        </div>
        @endif
    </div>

</div>
</x-app-layout>
