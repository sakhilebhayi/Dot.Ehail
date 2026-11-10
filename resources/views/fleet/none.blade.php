<x-fleet.page title="Fleet" heading="Run a fleet on Dot.Ehail">
    <x-eh.empty title="Register a fleet">
        <p style="margin:0">If you run several cars and drivers, or you drive your own car and want your vehicles, documents and earnings in one place, register a fleet. Our operations team reviews it; you can add vehicles while you wait, and drivers can apply to join once it is approved.</p>
        <x-slot:actions>
            <x-eh.button :href="route('fleet.register')" icon="fleet">Register a fleet</x-eh.button>
            @if (Route::has('drive.apply'))
                <x-eh.button :href="route('drive.apply')" variant="secondary">Apply to drive instead</x-eh.button>
            @endif
        </x-slot:actions>
    </x-eh.empty>
</x-fleet.page>
