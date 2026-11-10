<x-fleet-layout title="Fleet" :scope="$fleets->count() === 1 ? $fleets->first()->name : null">
    <x-slot:header>
        <div class="eh-pagehead__text">
            <h1 class="eh-h1">{{ $fleets->count() === 1 ? $fleets->first()->name : 'Your fleets' }}</h1>
        </div>
    </x-slot:header>

    @if ($fleets->isEmpty())
        <x-eh.empty title="Register a fleet">
            <p style="margin:0">If you run several cars and drivers, register your fleet. Our operations team reviews it, then drivers can join and you approve them.</p>
            <x-slot:actions>
                @if (Route::has('drive.apply'))
                    <x-eh.button :href="route('drive.apply')" icon="fleet">Register a fleet</x-eh.button>
                @endif
            </x-slot:actions>
        </x-eh.empty>
    @else
        <section aria-labelledby="fleets-title" class="eh-stack" style="--eh-stack-gap: var(--eh-space-3)">
            <h2 class="eh-h3" id="fleets-title">Fleets you own</h2>
            <ul class="eh-list">
                @foreach ($fleets as $fleet)
                    <x-eh.list-row :title="$fleet->name" icon="fleet" :href="Route::has('fleets.show') ? route('fleets.show', $fleet) : null">
                        <x-slot:trail>
                            <x-eh.badge :tone="$fleet->status === 'approved' ? 'go' : 'caution'">{{ $fleet->status === 'approved' ? 'Approved' : 'In review' }}</x-eh.badge>
                        </x-slot:trail>
                    </x-eh.list-row>
                @endforeach
            </ul>
        </section>
        <x-eh.empty title="Fleet management opens here" :pending="true">
            <p style="margin:0">Vehicles, driver assignments, documents that are about to expire and fleet earnings will be managed from this screen. It is being built now; your fleet page above still works.</p>
        </x-eh.empty>
    @endif
</x-fleet-layout>
