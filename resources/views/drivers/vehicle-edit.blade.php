<x-driver-layout title="Edit vehicle">
    <x-slot:head>
        @vite(['resources/css/driver.css'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head"><h1>Edit {{ $vehicle->plate_number }}</h1></div>
    </x-slot:header>
    <form method="POST" action="{{ route('drivers.vehicles.update', $vehicle) }}" class="dr-page dr-form">
        @csrf
        @method('PATCH')
        @if ($errors->any())<x-eh.banner tone="stop" title="Not saved">{{ $errors->first() }}</x-eh.banner>@endif
        @include('drivers._vehicle-fields')
        <div class="dr-actions__row">
            <x-eh.button type="submit" size="driver">Save changes</x-eh.button>
            <x-eh.button variant="ghost" size="driver" :href="route('drivers.vehicles.index')">Back to vehicles</x-eh.button>
        </div>
    </form>
</x-driver-layout>
