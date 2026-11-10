{{-- Document checklist: every required document for the driver and each vehicle, its state, and the upload. --}}
@php($counts = $documents['counts'])
<x-driver-layout title="Documents">
    <x-slot:head>
        @vite(['resources/css/driver.css', 'resources/js/driver/pages.js'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>Documents</h1>
            <p>{{ $counts['done'] }} of {{ $counts['total'] }} approved.@if($counts['waiting']) {{ $counts['waiting'] }} waiting for review.@endif @if($counts['action']) {{ $counts['action'] }} need you.@endif A person at Dot.Ehail checks every document; nothing is approved automatically.</p>
        </div>
    </x-slot:header>

    <div class="dr-page">
        <p class="dr-hold dr-alert dr-alert--caution">Uploading is paused while you drive. Stop somewhere safe to send documents.</p>

        <section class="dr-section" aria-labelledby="docs-you">
            <h2 id="docs-you">About you</h2>
            <ul class="dr-docs">
                @foreach ($documents['driver']['items'] as $item)
                    @include('driver._doc-item', ['item' => $item, 'vehicle' => null])
                @endforeach
            </ul>
        </section>

        @forelse ($documents['vehicles'] as $vehicleStatus)
            @php($vehicle = $vehicleStatus['vehicle'])
            <section class="dr-section" aria-labelledby="docs-v{{ $vehicle->id }}">
                <h2 id="docs-v{{ $vehicle->id }}">{{ trim("{$vehicle->color} {$vehicle->make} {$vehicle->model}") }} <span class="dr-plate">{{ $vehicle->plate_number }}</span></h2>
                @unless ($vehicle->is_active)<p class="dr-note">Not your active vehicle. Its documents count when you make it active.</p>@endunless
                <ul class="dr-docs">
                    @foreach ($vehicleStatus['items'] as $item)
                        @include('driver._doc-item', ['item' => $item, 'vehicle' => $vehicle])
                    @endforeach
                </ul>
            </section>
        @empty
            <x-eh.empty title="No vehicle yet" :pending="false">
                <p style="margin:0">Vehicle documents appear here once you add the car you drive.</p>
                <x-slot:actions><x-eh.button size="driver" :href="route('drivers.vehicles.index')" icon="car">Add a vehicle</x-eh.button></x-slot:actions>
            </x-eh.empty>
        @endforelse
    </div>
</x-driver-layout>
