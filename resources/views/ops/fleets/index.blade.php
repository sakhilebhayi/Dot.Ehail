<x-ops-layout title="Fleets">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head"><div class="ops-head__text"><h1>Fleets</h1><p class="ops-head__finding" @if($pending->isNotEmpty()) data-tone="caution" @endif>{{ $pending->count() }} {{ \Illuminate\Support\Str::plural('fleet', $pending->count()) }} awaiting approval. Until a fleet is approved, its owner cannot review driver applications.</p></div></header>
        <div class="ops-cols ops-cols--2">
            <section class="ops-panel ops-panel--flush" aria-labelledby="pf-h">
                <div class="ops-panel__head"><h2 id="pf-h">Awaiting approval</h2></div>
                <ul class="ops-queue">
                    @forelse ($pending as $fleet)
                        <li>
                            <span class="ops-queue__title">{{ $fleet->name }}</span>
                            <span class="ops-queue__meta">Owner {{ $fleet->owner?->name }} · {{ $fleet->owner?->email }} · created {{ $fleet->created_at?->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M Y') }}</span>
                            <span class="ops-queue__trail">
                                <form method="POST" action="{{ route('operator.fleets.approve', $fleet) }}" data-ops-confirm="Approve {{ $fleet->name }}? Its owner can then accept drivers." style="margin:0">@csrf<x-eh.button type="submit" size="sm">Approve</x-eh.button></form>
                            </span>
                        </li>
                    @empty
                        <li><p class="ops-none" style="padding:0">No fleet is waiting.</p></li>
                    @endforelse
                </ul>
            </section>
            <section class="ops-panel ops-panel--flush" aria-labelledby="af-h">
                <div class="ops-panel__head"><h2 id="af-h">Approved fleets</h2></div>
                @if ($approved->isEmpty())
                    <p class="ops-none">No fleet is approved yet.</p>
                @else
                    <table class="ops-table ops-table--compact">
                        <thead><tr><th>Fleet</th><th class="num">Drivers approved</th><th class="num">Applications</th></tr></thead>
                        <tbody>
                            @foreach ($approved as $fleet)
                                @php($c = ($counts[$fleet->id] ?? collect())->pluck('n', 'status'))
                                <tr><td>{{ $fleet->name }}<span class="sub">{{ $fleet->owner?->name }}</span></td><td class="num">{{ (int) ($c['approved'] ?? 0) }}</td><td class="num">{{ (int) ($c['pending'] ?? 0) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
    </div>
</x-ops-layout>
