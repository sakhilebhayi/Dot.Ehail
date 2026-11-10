{{-- Vehicles (driver app). One active vehicle at a time; its documents live on the documents page. --}}
@php
    $active = $vehicles->firstWhere('is_active', true);
    $classLabel = fn ($type) => $type === 'suv' ? 'SUV' : ucfirst($type);
@endphp
<x-driver-layout title="Vehicles">
    <x-slot:head>
        @vite(['resources/css/driver.css'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>Vehicles</h1>
            <p>
                @if ($active)
                    Driving {{ $active->year }} {{ $active->make }} {{ $active->model }}, {{ $classLabel($active->type) }}. You get offers for {{ collect($active->servableTiers())->map($classLabel)->join(', ', ' and ') }} rides.
                @else
                    No active vehicle. You cannot go online until you make one active.
                @endif
            </p>
        </div>
    </x-slot:header>

    <div class="dr-page">
        @if ($errors->any())<x-eh.banner tone="stop" title="Not saved">{{ $errors->first() }}</x-eh.banner>@endif

        @forelse ($vehicles as $vehicle)
            <section class="dr-panel" aria-labelledby="veh-{{ $vehicle->id }}">
                <div class="dr-doc__head">
                    <h2 class="dr-doc__name" id="veh-{{ $vehicle->id }}"><span class="dr-plate">{{ $vehicle->plate_number }}</span> {{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }}</h2>
                    @if ($vehicle->is_active)<x-eh.badge tone="go">Active</x-eh.badge>@else<x-eh.badge>Not active</x-eh.badge>@endif
                </div>
                <p class="dr-note">{{ $vehicle->color }}, {{ $vehicle->seats }} seats, {{ $classLabel($vehicle->type) }}.</p>
                @if ($vehicle->documents->isNotEmpty())
                    <p class="dr-note">Earlier uploads (before document review moved to the documents page):
                        @foreach ($vehicle->documents as $document)
                            <a class="dr-textlink" href="{{ route('drivers.documents.show', $document) }}">{{ $document->original_filename }}</a>@if(! $loop->last), @endif
                        @endforeach
                    </p>
                @endif
                @if ($canManage)
                    <div class="dr-actions__row">
                        <x-eh.button variant="secondary" size="driver" :href="route('drive.documents').'#doc-vehicle_licence_disc-v'.$vehicle->id" icon="document-check">Documents</x-eh.button>
                        <x-eh.button variant="ghost" size="driver" :href="route('drivers.vehicles.edit', $vehicle)">Edit</x-eh.button>
                        @if ($vehicle->is_active)
                            <form method="POST" action="{{ route('drivers.vehicles.deactivate', $vehicle) }}">
                                @csrf
                                <x-eh.button type="submit" variant="ghost" size="driver" block>Deactivate</x-eh.button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('drivers.vehicles.activate', $vehicle) }}">
                                @csrf
                                <x-eh.button type="submit" size="driver" block>Make active</x-eh.button>
                            </form>
                        @endif
                    </div>
                    @if ($vehicle->is_active)<p class="dr-note">Deactivating takes you offline until another vehicle is active.</p>@endif
                @endif
            </section>
        @empty
            <x-eh.empty title="No vehicles yet" :pending="false"><p style="margin:0">Add the car you drive below.</p></x-eh.empty>
        @endforelse

        @if ($canManage)
            <section class="dr-section" aria-labelledby="add-h">
                <h2 id="add-h">Add a vehicle</h2>
                <form method="POST" action="{{ route('drivers.vehicles.store') }}" class="dr-form dr-panel">
                    @csrf
                    @include('drivers._vehicle-fields', ['vehicle' => null])
                    <label class="dr-reason"><input type="checkbox" name="activate" value="1" @checked(old('activate'))> Make it my active vehicle{{ $active ? ' (switches off '.$active->plate_number.')' : '' }}</label>
                    <x-eh.button type="submit" size="driver">Add vehicle</x-eh.button>
                </form>
            </section>
        @else
            <p class="dr-note">Your driver profile is {{ $profile->status }}, so vehicles cannot be changed.</p>
        @endif
    </div>
</x-driver-layout>
