@php($v = $vehicle ?? null)
<div class="dr-form__grid">
    <x-eh.field label="Make" name="make" id="vehicle-make" size="driver" maxlength="100" required :value="$v?->make" />
    <x-eh.field label="Model" name="model" id="vehicle-model" size="driver" maxlength="100" required :value="$v?->model" />
    <x-eh.field label="Year" name="year" id="vehicle-year" type="number" size="driver" min="1980" :max="date('Y') + 1" required :value="$v?->year" />
    <x-eh.field label="Colour" name="color" id="vehicle-color" size="driver" maxlength="50" required :value="$v?->color" />
    <x-eh.field label="Number plate" name="plate_number" id="vehicle-plate" size="driver" maxlength="20" autocapitalize="characters" required :value="$v?->plate_number" />
    <x-eh.field label="Passenger seats" name="seats" id="vehicle-seats" type="number" size="driver" min="1" max="8" required :value="$v?->seats ?? 4" />
    <x-eh.select label="Class" name="type" id="vehicle-type" :options="collect(array_keys(config('ehail.tariff.multipliers')))->mapWithKeys(fn ($t) => [$t => $t === 'suv' ? 'SUV' : ucfirst($t)])->all()" :value="old('type', $v?->type ?? 'standard')" />
</div>
