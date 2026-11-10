<x-ops-layout title="Ride categories">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head"><div class="ops-head__text"><h1>Pricing</h1><p class="ops-head__finding">{{ $categories->where('is_active', true)->count() }} of {{ $categories->count() }} ride categories are offered. A category is offered only where a live tariff exists for it.</p></div></header>
        @include('ops.pricing.tabs', ['active' => 'catalogue'])
        <section class="ops-panel ops-panel--flush" aria-label="Ride categories">
            <div class="ops-tablewrap"><table class="ops-table">
                <thead><tr><th>Category</th><th>Vehicles that qualify</th><th class="num">Seats</th><th class="num">Driver rating floor</th><th>Offered</th></tr></thead>
                <tbody>
                    @foreach ($categories as $c)
                        <tr>
                            <td><strong>{{ $c->name }}</strong><span class="sub">{{ $c->key }}{{ $c->fleet_only ? ' · fleet drivers only' : '' }}{{ $c->service_kind !== 'ride' ? ' · '.$c->service_kind : '' }}</span></td>
                            <td>@forelse ($c->vehicleRules as $rule){{ implode(', ', (array) $rule->vehicle_types) ?: 'any class' }}{{ $rule->max_age_years ? ', at most '.$rule->max_age_years.' years old' : '' }}{{ $rule->min_seats ? ', '.$rule->min_seats.'+ seats' : '' }}{{ $rule->required_features ? ', needs '.implode(', ', $rule->required_features) : '' }}@if(!$loop->last)<br>@endif @empty<span class="ops-dash">no rule</span>@endforelse</td>
                            <td class="num">{{ $c->capacity }}</td>
                            <td class="num">{{ $c->driver_rating_floor ? number_format($c->driver_rating_floor / 100, 2) : '-' }}</td>
                            <td>
                                @if ($canManage)
                                    <form data-ops-action data-ops-method="PATCH" method="post" action="{{ route('pricing.ops.categories.update', $c->id) }}" data-ops-done="{{ $c->name }} updated." style="margin:0">
                                        <input type="hidden" name="is_active" value="{{ $c->is_active ? '0' : '1' }}">
                                        <x-eh.button type="submit" size="sm" :variant="$c->is_active ? 'secondary' : 'primary'">{{ $c->is_active ? 'On: switch off' : 'Off: switch on' }}</x-eh.button>
                                    </form>
                                @else
                                    <x-eh.badge :tone="$c->is_active ? 'go' : 'neutral'">{{ $c->is_active ? 'On' : 'Off' }}</x-eh.badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    </div>
</x-ops-layout>
