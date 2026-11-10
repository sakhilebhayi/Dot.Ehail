<x-ops-layout title="Fare simulator">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Pricing</h1>
                <p class="ops-head__finding">Price one trip under draft tariffs and the live one, side by side. Nothing is stored and nobody is quoted.</p>
            </div>
        </header>
        @include('ops.pricing.tabs', ['active' => 'simulator'])
        <div class="ops-split">
            <section class="ops-panel" aria-labelledby="sim-h">
                <div class="ops-panel__head"><h2 id="sim-h">Results</h2><span class="ops-panel__meta" data-sim-trip>run a simulation</span></div>
                <div data-sim-results>
                    <p class="ops-none">Choose a category and a trip, then simulate.</p>
                </div>
            </section>
            <section class="ops-panel" aria-labelledby="trip-h">
                <div class="ops-panel__head"><h2 id="trip-h">Trip</h2></div>
                <form class="ops-act" data-ops-simulate method="post" action="{{ route('pricing.ops.rule-sets.simulate') }}">
                    <x-eh.select label="Category" name="category" :options="$categories->pluck('name', 'key')->all()" id="sim-cat" />
                    <fieldset style="border:0;padding:0;margin:0;display:grid;gap:var(--eh-space-2)">
                        <legend class="eh-field__label" style="margin-bottom:var(--eh-space-2)">Draft tariffs to compare</legend>
                        @forelse ($drafts as $draft)
                            <label class="ops-check"><input type="checkbox" name="rule_set_ids[]" value="{{ $draft->id }}" @checked($preselect === $draft->id)> {{ $draft->name }} v{{ $draft->version }} <span class="ops-stamp">{{ $draft->category?->name ?? 'every category' }}</span></label>
                        @empty
                            <p class="ops-note ops-note--small">No draft exists. Make one from a tariff's page; the live tariff is still simulated.</p>
                        @endforelse
                        <input type="hidden" name="include_live" value="0"><label class="ops-check"><input type="checkbox" name="include_live" value="1" checked> Include the live tariff</label>
                    </fieldset>
                    <p class="ops-note ops-note--small">A road route between two points needs a routing provider. Without one, enter the distance and time yourself.</p>
                    <div class="ops-cols ops-cols--2">
                        <x-eh.field label="Distance (km)" name="distance_km" inputmode="decimal" id="sim-km" value="12" />
                        <x-eh.field label="Time (minutes)" name="duration_min" type="number" min="0" id="sim-min" value="25" />
                        <x-eh.field label="Waiting (minutes)" name="waiting_min" type="number" min="0" id="sim-wait" value="0" />
                        <x-eh.field label="Tolls paid (rand)" name="tolls_rand" inputmode="decimal" data-ops-minor="tolls_minor" id="sim-tolls" :optional="true" />
                    </div>
                    <details>
                        <summary class="eh-field__label" style="cursor:pointer">Or a route between two points</summary>
                        <div class="ops-cols ops-cols--2" style="margin-top:var(--eh-space-3)">
                            <x-eh.field label="Pickup latitude" name="pickup_lat" inputmode="decimal" id="sim-plat" :optional="true" />
                            <x-eh.field label="Pickup longitude" name="pickup_lng" inputmode="decimal" id="sim-plng" :optional="true" />
                            <x-eh.field label="Drop-off latitude" name="dropoff_lat" inputmode="decimal" id="sim-dlat" :optional="true" />
                            <x-eh.field label="Drop-off longitude" name="dropoff_lng" inputmode="decimal" id="sim-dlng" :optional="true" />
                        </div>
                    </details>
                    <div class="ops-act__row"><x-eh.button type="submit" size="sm" icon="sliders">Simulate</x-eh.button></div>
                </form>
            </section>
        </div>
    </div>
</x-ops-layout>
