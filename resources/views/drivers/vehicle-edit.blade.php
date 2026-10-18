<x-app-layout>
<div style="padding:2rem 2.5rem 3rem;max-width:760px;">
    <div style="margin-bottom:1.5rem;">
        <a href="{{ route('drivers.vehicles.index') }}" style="font-size:0.78rem;color:#71717a;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
            <span class="material-symbols-rounded" style="font-size:16px;">arrow_back</span>
            Back to vehicles
        </a>
    </div>

    <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:700;color:#f4f4f5;margin:0 0 1.5rem;letter-spacing:-0.01em;">Edit {{ $vehicle->plate_number }}</h1>

    @if ($errors->any())
    <div style="margin-bottom:1.25rem;padding:0.65rem 1rem;border-radius:0.6rem;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.25);color:#f87171;font-size:0.8rem;">
        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
    </div>
    @endif

    <form method="POST" action="{{ route('drivers.vehicles.update', $vehicle) }}" class="dot-card" style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">
        @csrf
        @method('PATCH')
        @include('drivers._vehicle-fields')
        <button type="submit" class="dot-btn dot-btn-primary" style="align-self:flex-start;">Save changes</button>
    </form>
</div>
</x-app-layout>
