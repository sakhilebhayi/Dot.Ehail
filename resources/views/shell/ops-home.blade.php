<x-ops-layout title="Live operations">
    <x-slot:header>
        <div class="eh-pagehead__text">
            <h1 class="eh-h1">Live operations</h1>
        </div>
    </x-slot:header>

    <x-eh.empty title="The live operations centre opens here" :pending="true">
        <p style="margin:0">Rides in progress, drivers online, safety incidents and support cases will be worked from this screen. It is being built now. Today's operator pages still work.</p>
        <x-slot:actions>
            @if (Route::has('operator.rides.index'))
                <x-eh.button :href="route('operator.rides.index')" icon="route">Ride operations</x-eh.button>
            @endif
            @if (Route::has('operator.fleets.index'))
                <x-eh.button variant="secondary" :href="route('operator.fleets.index')" icon="fleet">Fleet review</x-eh.button>
            @endif
        </x-slot:actions>
    </x-eh.empty>
</x-ops-layout>
