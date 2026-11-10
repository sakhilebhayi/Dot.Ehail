@props(['value'])

<label {{ $attributes->merge(['class' => 'eh-field__label', 'style' => 'display:block']) }}>
    {{ $value ?? $slot }}
</label>
