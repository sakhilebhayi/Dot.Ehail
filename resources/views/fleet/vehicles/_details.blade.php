{{-- Vehicle details fields, shared by add and edit. $vehicle may be null. $driverOwned limits the form to doors and features. --}}
@php($driverOwned = $vehicle?->owned_by === 'driver')
@unless ($driverOwned)
    <x-eh.field label="Number plate" name="plate_number" :value="$vehicle?->plate_number" hint="As on the vehicle, for example CA 123 456 or ND 456-789." />
    <x-eh.field label="Make" name="make" :value="$vehicle?->make" />
    <x-eh.field label="Model" name="model" :value="$vehicle?->model" />
    <x-eh.field label="Year" name="year" type="number" :value="$vehicle?->year" min="1980" :max="now()->year + 1" />
    <x-eh.field label="Colour" name="color" :value="$vehicle?->color" />
    <x-eh.select label="Class" name="type" :value="$vehicle?->type ?? 'standard'" :options="['economy' => 'Economy', 'standard' => 'Standard', 'premium' => 'Premium', 'suv' => 'SUV']" />
    <x-eh.field label="Passenger seats" name="seats" type="number" :value="$vehicle?->seats ?? 4" min="1" max="16" />
@endunless
<x-eh.field label="Doors" name="doors" type="number" :value="$vehicle?->doors" min="2" max="6" :optional="true" hint="Some ride categories ask for four doors." />
@unless ($driverOwned)
    <x-eh.field label="Odometer (km)" name="odometer_km" type="number" :value="$vehicle?->odometer_km" min="0" :optional="true" />
@endunless
<fieldset class="fl-checks fl-form__full">
    <legend>Features</legend>
    @foreach ($features as $key => $label)
        <label class="fl-check"><input type="checkbox" name="features[]" value="{{ $key }}" @checked(in_array($key, old('features', $vehicle?->features ?? []), true))><span>{{ $label }}</span></label>
    @endforeach
    <p class="eh-field__hint">Ride categories read these (Accessible needs wheelchair access). Declare only what the vehicle has.</p>
</fieldset>
