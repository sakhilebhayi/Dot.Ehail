<x-passenger-layout title="Ride">
    <x-slot:header>
        <div class="eh-pagehead__text">
            <h1 class="eh-h1">Ride</h1>
        </div>
    </x-slot:header>

    <x-eh.empty title="Booking opens here" :pending="true">
        <p style="margin:0">This is where you will set your pickup and drop-off on a map, compare ride types with the full fare breakdown before you request, and follow your driver to the kerb. The new booking flow is being built now.</p>
        <x-slot:actions>
            @if (Route::has('rides.create'))
                <x-eh.button :href="route('rides.create')" icon="pickup">Request with the current form</x-eh.button>
            @endif
            @if (Route::has('rides.index'))
                <x-eh.button variant="secondary" :href="route('rides.index')" icon="history">Your trips</x-eh.button>
            @endif
        </x-slot:actions>
    </x-eh.empty>
</x-passenger-layout>
