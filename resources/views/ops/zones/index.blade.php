@php($approx = $areas->where('is_approximate', true)->count())
<x-ops-layout title="Service areas">
    <x-slot:head>@include('ops.partials.assets')</x-slot:head>
    <div class="ops">
        <header class="ops-head">
            <div class="ops-head__text">
                <h1>Pricing</h1>
                <p class="ops-head__finding" @if($approx > 0) data-tone="caution" @endif>{{ $areas->where('is_active', true)->count() }} active service {{ \Illuminate\Support\Str::plural('area', $areas->where('is_active', true)->count()) }}, {{ $zones->where('is_active', true)->count() }} active {{ \Illuminate\Support\Str::plural('zone', $zones->where('is_active', true)->count()) }}.@if($approx > 0) {{ $approx }} {{ \Illuminate\Support\Str::plural('area', $approx) }} still use the approximate reference outline: review before launch.@endif Pickups outside every active area cannot be quoted.</p>
            </div>
        </header>
        @include('ops.pricing.tabs', ['active' => 'zones'])
        <div class="ops-zones" data-ops-zones data-can-edit="{{ $canEdit ? '1' : '0' }}" data-store-area="{{ route('ops.zones.areas.store') }}">
            <script type="application/json" data-zones-initial>@json($shapes)</script>
            <div class="ops-stack">
                @if ($canEdit)
                    <section class="ops-panel" aria-labelledby="draw-h">
                        <div class="ops-panel__head"><h2 id="draw-h">Draw</h2></div>
                        <div class="ops-act" data-zone-editor>
                            <p class="ops-note ops-note--small" data-zone-hint>Choose a shape to edit, or start a new one. Click the map to add corners; drag a corner to move it; double-click a corner to remove it.</p>
                            <div class="eh-field" data-zone-name-wrap hidden><label class="eh-field__label" for="zone-name">Name</label><input id="zone-name" class="eh-input" data-zone-name maxlength="120"></div>
                            <div class="eh-field" data-zone-kind-wrap hidden><label class="eh-field__label" for="zone-kind">Kind of zone</label><div class="eh-select-wrap"><select id="zone-kind" class="eh-select" data-zone-kind>@foreach (\App\Domain\Geo\Models\Zone::KINDS as $kind)<option value="{{ $kind }}">{{ ucfirst(str_replace('_', ' ', $kind)) }}</option>@endforeach</select><x-eh.icon name="chevron-down" /></div></div>
                            <div class="eh-field" data-zone-fee-wrap hidden><label class="eh-field__label" for="zone-fee">Fee code (matches tariff zone fees)</label><input id="zone-fee" class="eh-input" data-zone-fee maxlength="64" placeholder="for example airport"></div>
                            <label class="ops-check" data-zone-exact-wrap hidden><input type="checkbox" data-zone-exact> This outline is now checked, not approximate</label>
                            <p class="ops-act__error" data-zone-error role="alert" hidden></p>
                            <div class="ops-act__row">
                                <x-eh.button size="sm" variant="secondary" icon="plus" data-zone-new-area>New service area</x-eh.button>
                                <x-eh.button size="sm" data-zone-save hidden>Save shape</x-eh.button>
                                <x-eh.button size="sm" variant="ghost" data-zone-cancel hidden>Cancel</x-eh.button>
                            </div>
                        </div>
                    </section>
                @endif
                <section class="ops-panel ops-panel--flush" aria-labelledby="areas-h">
                    <div class="ops-panel__head"><h2 id="areas-h">Areas and zones</h2></div>
                    @if ($areas->isEmpty())
                        <p class="ops-none">No service area exists. Run the reference areas command or draw one.</p>
                    @else
                        <ul class="ops-queue">
                            @foreach ($areas as $area)
                                <li>
                                    <span class="ops-queue__title">{{ $area->name }}</span>
                                    <span class="ops-queue__meta">{{ $area->is_active ? 'Active' : 'Off' }}{{ $area->is_approximate ? ' · approximate outline' : '' }} · {{ count($area->polygon ?? []) }} corners</span>
                                    <span class="ops-queue__trail">
                                        <x-eh.button size="sm" variant="ghost" data-zone-show="area-{{ $area->id }}">Show</x-eh.button>
                                        @if ($canEdit)
                                            <x-eh.button size="sm" variant="ghost" data-zone-edit="area-{{ $area->id }}">Edit shape</x-eh.button>
                                            <x-eh.button size="sm" variant="ghost" data-zone-new-zone="{{ $area->id }}">Add zone</x-eh.button>
                                            <form data-ops-action data-ops-method="PATCH" method="post" action="{{ route('ops.zones.areas.update', $area->id) }}" data-ops-confirm="{{ $area->is_active ? 'Switch off '.$area->name.'? Pickups there can no longer be quoted.' : 'Switch on '.$area->name.'? Pickups there can be quoted where a tariff exists.' }}" data-ops-done="{{ $area->name }} switched {{ $area->is_active ? 'off' : 'on' }}." style="margin:0">
                                                <input type="hidden" name="is_active" value="{{ $area->is_active ? '0' : '1' }}">
                                                <x-eh.button type="submit" size="sm" :variant="$area->is_active ? 'ghost' : 'secondary'">{{ $area->is_active ? 'Switch off' : 'Switch on' }}</x-eh.button>
                                            </form>
                                        @endif
                                    </span>
                                </li>
                                @foreach ($zones->where('service_area_id', $area->id) as $zone)
                                    <li style="padding-left:var(--eh-space-8)">
                                        <span class="ops-queue__title">{{ $zone->name }}</span>
                                        <span class="ops-queue__meta">{{ ucfirst(str_replace('_', ' ', $zone->kind)) }}{{ $zone->fee_code ? ' · fee '.$zone->fee_code : '' }} · {{ $zone->is_active ? 'active' : 'off' }}{{ $zone->is_approximate ? ' · approximate' : '' }}</span>
                                        <span class="ops-queue__trail">
                                            <x-eh.button size="sm" variant="ghost" data-zone-show="zone-{{ $zone->id }}">Show</x-eh.button>
                                            @if ($canEdit)
                                                <x-eh.button size="sm" variant="ghost" data-zone-edit="zone-{{ $zone->id }}">Edit shape</x-eh.button>
                                                <form data-ops-action data-ops-method="PATCH" method="post" action="{{ route('ops.zones.update', $zone->id) }}" data-ops-done="{{ $zone->name }} switched {{ $zone->is_active ? 'off' : 'on' }}." style="margin:0">
                                                    <input type="hidden" name="is_active" value="{{ $zone->is_active ? '0' : '1' }}">
                                                    <x-eh.button type="submit" size="sm" :variant="$zone->is_active ? 'ghost' : 'secondary'">{{ $zone->is_active ? 'Switch off' : 'Switch on' }}</x-eh.button>
                                                </form>
                                            @endif
                                        </span>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
            <section class="ops-panel ops-panel--flush" aria-labelledby="zmap-h">
                <div class="ops-panel__head" style="padding-bottom:var(--eh-space-3)"><h2 id="zmap-h">Map</h2>
                    <ul class="ops-legend"><li><span class="ops-dot ops-dot--busy"></span>Service area</li><li><span class="ops-dot ops-dot--pickup"></span>Zone</li><li><span class="ops-dot ops-dot--stale"></span>Switched off</li></ul>
                </div>
                <div class="ops-map" style="height:40rem" data-eh-map data-map-state="pending">
                    <div data-eh-map-canvas aria-label="Map of service areas and zones"></div>
                    <p class="ops-map__status" data-ops-map-status role="status">Loading the map.</p>
                </div>
            </section>
        </div>
    </div>
</x-ops-layout>
