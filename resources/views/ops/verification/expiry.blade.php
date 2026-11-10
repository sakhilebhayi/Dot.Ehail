@php($overdue = $expiring->filter(fn ($d) => $d->expires_on->isPast())->count())
<x-ops-layout title="Document expiry">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Verification</h1>
                <p class="ops-head__finding" @if($expiring->isNotEmpty()) data-tone="caution" @endif>{{ $expiring->count() }} approved {{ \Illuminate\Support\Str::plural('document', $expiring->count()) }} expire within {{ $within }} days; {{ $expiredRecently->count() }} expired in the last 30 days. Drivers are warned at 30 and 7 days and taken offline when a required document expires.</p>
            </div>
        </header>
        @include('ops.verification.tabs', ['active' => 'expiry'])
        <form class="ops-filter" method="GET" action="{{ route('ops.verification.expiry') }}">
            <x-eh.select label="Expiring within" name="within" :options="['7' => '7 days', '30' => '30 days', '60' => '60 days', '90' => '90 days']" :value="(string) $within" />
            <x-eh.button type="submit" size="sm" icon="filter">Apply</x-eh.button>
        </form>
        <div class="ops-cols ops-cols--2">
            <section class="ops-panel ops-panel--flush" aria-labelledby="exp-h">
                <div class="ops-panel__head"><h2 id="exp-h">Expiring</h2><span class="ops-panel__meta">soonest first</span></div>
                @if ($expiring->isEmpty())
                    <p class="ops-none">Nothing expires within {{ $within }} days.</p>
                @else
                    <table class="ops-table ops-table--compact">
                        <thead><tr><th>Document</th><th>Driver</th><th class="num">Expires</th></tr></thead>
                        <tbody>
                            @foreach ($expiring as $doc)
                                @php($days = (int) now()->startOfDay()->diffInDays($doc->expires_on, false))
                                <tr>
                                    <td><a href="{{ route('ops.verification.show', $doc->id) }}">{{ $types[$doc->type]['label'] ?? $doc->type }}</a>@if($doc->vehicle)<span class="sub">{{ $doc->vehicle->plate_number }}</span>@endif</td>
                                    <td>{{ $doc->owner?->name }}</td>
                                    <td class="num"><span class="ops-timer" data-tone="{{ $days <= 7 ? 'stop' : ($days <= 30 ? 'caution' : 'calm') }}">{{ $doc->expires_on->format('d M Y') }} · {{ $days < 0 ? abs($days).' d ago' : 'in '.$days.' d' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
            <section class="ops-panel ops-panel--flush" aria-labelledby="expd-h">
                <div class="ops-panel__head"><h2 id="expd-h">Expired in the last 30 days</h2></div>
                @if ($expiredRecently->isEmpty())
                    <p class="ops-none">No document expired in the last 30 days.</p>
                @else
                    <table class="ops-table ops-table--compact">
                        <thead><tr><th>Document</th><th>Driver</th><th class="num">Expired</th></tr></thead>
                        <tbody>
                            @foreach ($expiredRecently as $doc)
                                <tr><td><a href="{{ route('ops.verification.show', $doc->id) }}">{{ $types[$doc->type]['label'] ?? $doc->type }}</a></td><td>{{ $doc->owner?->name }}</td><td class="num">{{ $doc->expired_at?->format('d M Y') }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
    </div>
</x-ops-layout>
