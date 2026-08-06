@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'border-[var(--line)] bg-[var(--panel)] text-[var(--ink)] font-body focus:border-[var(--gold)] focus:ring-[var(--gold)] rounded-lg shadow-sm']) !!}>
