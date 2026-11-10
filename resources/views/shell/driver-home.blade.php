@php
    $driverState = match (true) {
        $profile === null => ['offline', 'Not registered to drive', 'Apply once and we review your documents before you take a ride.', 'offline'],
        $profile->status !== 'approved' => ['offline', 'Application in review', 'You can drive once your documents are approved.', 'document'],
        (bool) $profile->is_online => ['online', 'You are online', 'Ride offers will show here with pickup distance and your earnings before you accept.', 'online'],
        default => ['offline', 'You are offline', 'Go online when you are ready to take rides.', 'offline'],
    };
@endphp
<x-driver-layout title="Drive">
    <x-slot:state>
        <section class="eh-statebar" data-state="{{ $driverState[0] }}" aria-labelledby="drive-state-title">
            <x-eh.icon :name="$driverState[3]" class="eh-statebar__mark" />
            <div class="eh-statebar__text">
                <h1 class="eh-statebar__title" id="drive-state-title">{{ $driverState[1] }}</h1>
                <p class="eh-statebar__sub">{{ $driverState[2] }}</p>
            </div>
        </section>
    </x-slot:state>

    @if ($profile === null)
        <x-eh.empty title="Drive with Dot.Ehail" :pending="false">
            <p style="margin:0">Join a fleet or register your own, add your vehicle and upload your documents. Every driver is reviewed by a person before their first trip.</p>
            <x-slot:actions>
                @if (Route::has('drive.apply'))
                    <x-eh.button size="driver" :href="route('drive.apply')" icon="steering-wheel">Apply to drive</x-eh.button>
                @endif
            </x-slot:actions>
        </x-eh.empty>
    @else
        <x-eh.empty title="Ride offers arrive here" :pending="true">
            <p style="margin:0">The new driver app shows each offer with the pickup distance, the trip and what you earn before you accept. It is being built now. Until it opens, the driver desk lists the rides you can take.</p>
            <x-slot:actions>
                @if ($profile->status === 'approved' && Route::has('rides.available'))
                    <x-eh.button size="driver" :href="route('rides.available')" icon="list">Open the driver desk</x-eh.button>
                @endif
                @if (Route::has('drivers.vehicles.index'))
                    <x-eh.button size="lg" variant="secondary" :href="route('drivers.vehicles.index')" icon="car">My vehicles</x-eh.button>
                @endif
            </x-slot:actions>
        </x-eh.empty>
    @endif
</x-driver-layout>
