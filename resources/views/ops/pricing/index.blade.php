@php
    $live = $lineages->filter(fn ($l) => $l['live'] !== null)->count();
    $examples = $lineages->filter(fn ($l) => $l['live']?->is_example)->count();
@endphp
<x-ops-layout title="Pricing">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Pricing</h1>
                <p class="ops-head__finding" @if($examples > 0 || $live === 0) data-tone="caution" @endif>
                    @if ($lineages->isEmpty()) No tariff exists: every quote answers "no tariff" until one is published.
                    @else {{ $live }} live {{ \Illuminate\Support\Str::plural('tariff', $live) }}{{ $examples > 0 ? ', '.$examples.' of them the example tariff that must be reviewed before launch' : '' }}. A published tariff never changes; edits make a new version.
                    @endif
                </p>
            </div>
        </header>
        @include('ops.pricing.tabs', ['active' => 'tariffs'])
        <section class="ops-panel ops-panel--flush" aria-label="Tariffs">
            @if ($lineages->isEmpty())
                <p class="ops-none" style="padding:var(--eh-space-5)">No tariff has been drafted. Create one through the pricing API or install the example tariff outside production.</p>
            @else
                <div class="ops-tablewrap"><table class="ops-table">
                    <thead><tr><th>Tariff</th><th>State</th><th>Applies to</th><th class="num">Base</th><th class="num">Per km</th><th class="num">Per min</th><th class="num">Minimum</th><th class="num">Versions</th></tr></thead>
                    <tbody>
                        @foreach ($lineages as $l)
                            @php($v = $l['live'] ?? $l['latest'])
                            <tr>
                                <td><a href="{{ route('ops.pricing.show', $v->id) }}">{{ $v->name }}</a><span class="sub">{{ $v->country }} · {{ $v->currency }}</span></td>
                                <td>@include('ops.pricing.status', ['v' => $v]) @if($l['draft'] && $l['draft']->id !== $v->id)<x-eh.badge tone="info">Draft v{{ $l['draft']->version }} open</x-eh.badge>@endif</td>
                                <td>{{ $v->category?->name ?? 'Every category' }}<span class="sub">{{ $v->service_area_id ? ($areas[$v->service_area_id] ?? 'area '.$v->service_area_id) : 'whole market' }}{{ $v->zone_slug ? ' · pickup zone '.$v->zone_slug : '' }}{{ $v->time_windows ? ' · time windows' : '' }}</span></td>
                                <td class="num"><x-eh.money :minor="$v->base_minor" :currency="$v->currency" /></td>
                                <td class="num"><x-eh.money :minor="$v->per_km_minor" :currency="$v->currency" /></td>
                                <td class="num"><x-eh.money :minor="$v->per_minute_minor" :currency="$v->currency" /></td>
                                <td class="num"><x-eh.money :minor="$v->minimum_minor" :currency="$v->currency" /></td>
                                <td class="num">{{ $l['count'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>
    </div>
</x-ops-layout>
