<x-fleet.page :fleet="$fleet" :fleets="$fleets" title="Add a vehicle" line="A vehicle the fleet holds. After adding it, allow drivers to drive it and give it to one of them; they upload its documents in the driver app.">
    <form method="POST" action="{{ route('fleet.vehicles.store') }}" class="fl-panel">
        @csrf
        <div class="fl-form fl-form--3">
            @include('fleet.vehicles._details', ['vehicle' => null])
            <div class="fl-form__actions">
                <x-eh.button type="submit" icon="plus">Add vehicle</x-eh.button>
                <x-eh.button :href="route('fleet.vehicles')" variant="ghost">Cancel</x-eh.button>
            </div>
        </div>
    </form>
</x-fleet.page>
