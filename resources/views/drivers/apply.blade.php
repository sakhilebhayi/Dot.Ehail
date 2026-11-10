{{-- Apply to drive (driver app, R-46). Join an approved fleet or drive independently; documents follow on the next screen. --}}
@php
    $mode = old('mode', $fleets->isEmpty() ? 'independent' : 'fleet');
    $classLabel = fn ($type) => $type === 'suv' ? 'SUV' : ucfirst($type);
@endphp
<x-driver-layout title="Apply to drive">
    <x-slot:head>
        @vite(['resources/css/driver.css'])
    </x-slot:head>
    <x-slot:header>
        <div class="dr-head">
            <h1>Apply to drive</h1>
            <p>Three short parts. After this you upload your documents, and a person reviews everything before your first trip.</p>
        </div>
    </x-slot:header>

    <form method="POST" action="{{ route('drive.apply.store') }}" class="dr-page dr-form" novalidate>
        @csrf
        @if ($errors->any())
            <x-eh.banner tone="stop" title="Check the highlighted answers">{{ $errors->first() }}</x-eh.banner>
        @endif

        <section class="dr-section" aria-labelledby="ap-you">
            <h2 id="ap-you">You</h2>
            <p class="dr-note">Your numbers are stored encrypted. Fleet owners see only the last four digits.</p>
            <div class="dr-form__grid">
                <x-eh.field label="Driver's licence number" name="license_number" size="driver" autocomplete="off" required />
                <x-eh.field label="South African ID or passport number" name="id_number" size="driver" autocomplete="off" required />
            </div>
        </section>

        <section class="dr-section" aria-labelledby="ap-how">
            <h2 id="ap-how">How you drive</h2>
            <div class="dr-choice" role="radiogroup" aria-labelledby="ap-how">
                <label>
                    <input type="radio" name="mode" value="fleet" @checked($mode === 'fleet') @disabled($fleets->isEmpty())>
                    <strong>Join a fleet</strong>
                    <span>{{ $fleets->isEmpty() ? 'No fleet is open to new drivers yet.' : 'The fleet owner reviews your application.' }}</span>
                </label>
                <label>
                    <input type="radio" name="mode" value="independent" @checked($mode === 'independent')>
                    <strong>Drive independently</strong>
                    <span>We register a one-driver fleet in your name; Dot.Ehail operations reviews it and you.</span>
                </label>
            </div>
            @if ($fleets->isNotEmpty())
                <x-eh.select label="Fleet to join" name="existing_fleet_id" :options="['' => 'Choose a fleet'] + $fleets->pluck('name', 'id')->all()" :value="old('existing_fleet_id')" />
            @endif
            <x-eh.field label="Name for your own fleet" name="new_fleet_name" size="driver" optional hint="Only for driving independently. Leave empty to use your name." />
        </section>

        <section class="dr-section" aria-labelledby="ap-car">
            <h2 id="ap-car">Your vehicle</h2>
            <div class="dr-form__grid">
                <x-eh.field label="Make" name="vehicle_make" size="driver" required />
                <x-eh.field label="Model" name="vehicle_model" size="driver" required />
                <x-eh.field label="Year" name="vehicle_year" type="number" size="driver" min="1980" :max="date('Y') + 1" inputmode="numeric" required />
                <x-eh.field label="Colour" name="vehicle_color" size="driver" required />
                <x-eh.field label="Number plate" name="vehicle_plate_number" size="driver" autocapitalize="characters" required />
                <x-eh.select label="Class" name="vehicle_type" :options="collect(['economy', 'standard', 'premium', 'suv'])->mapWithKeys(fn ($t) => [$t => $classLabel($t)])->all()" :value="old('vehicle_type', 'standard')" />
            </div>
            <p class="dr-note">Vehicle photos, licence disc, roadworthy and insurance are uploaded on the next screen.</p>
        </section>

        <x-eh.button type="submit" size="driver" icon-right="arrow-right" block>Send application</x-eh.button>
    </form>
</x-driver-layout>
