@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-body font-medium text-sm text-[var(--ink-soft)]']) }}>
    {{ $value ?? $slot }}
</label>
