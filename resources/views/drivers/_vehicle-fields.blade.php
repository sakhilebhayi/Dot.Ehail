@php
    $labelStyle = 'display:block;font-size:0.75rem;color:#a1a1aa;margin-bottom:0.25rem;';
    $v = $vehicle ?? null;
@endphp
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.9rem;">
    <div>
        <label for="vehicle-make" style="{{ $labelStyle }}">Make</label>
        <input id="vehicle-make" class="dot-input" type="text" name="make" maxlength="100" required value="{{ old('make', $v?->make) }}">
    </div>
    <div>
        <label for="vehicle-model" style="{{ $labelStyle }}">Model</label>
        <input id="vehicle-model" class="dot-input" type="text" name="model" maxlength="100" required value="{{ old('model', $v?->model) }}">
    </div>
    <div>
        <label for="vehicle-year" style="{{ $labelStyle }}">Year</label>
        <input id="vehicle-year" class="dot-input" type="number" name="year" min="1980" max="{{ date('Y') + 1 }}" required value="{{ old('year', $v?->year) }}">
    </div>
    <div>
        <label for="vehicle-color" style="{{ $labelStyle }}">Colour</label>
        <input id="vehicle-color" class="dot-input" type="text" name="color" maxlength="50" required value="{{ old('color', $v?->color) }}">
    </div>
    <div>
        <label for="vehicle-plate" style="{{ $labelStyle }}">Number plate</label>
        <input id="vehicle-plate" class="dot-input" type="text" name="plate_number" maxlength="20" required value="{{ old('plate_number', $v?->plate_number) }}" style="text-transform:uppercase;">
    </div>
    <div>
        <label for="vehicle-seats" style="{{ $labelStyle }}">Passenger seats</label>
        <input id="vehicle-seats" class="dot-input" type="number" name="seats" min="1" max="8" required value="{{ old('seats', $v?->seats ?? 4) }}">
    </div>
    <div>
        <label for="vehicle-type" style="{{ $labelStyle }}">Class</label>
        <select id="vehicle-type" class="dot-input" name="type" required>
            @foreach(array_keys(config('ehail.tariff.multipliers')) as $type)
            <option value="{{ $type }}" @selected(old('type', $v?->type ?? 'standard') === $type)>{{ $type === 'suv' ? 'SUV' : ucfirst($type) }}</option>
            @endforeach
        </select>
    </div>
</div>
