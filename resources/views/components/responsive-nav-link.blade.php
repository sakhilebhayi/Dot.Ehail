@props(['active'])

{{-- Legacy Jetstream navigation (kept hidden inside the legacy shell); styled as the kit's side links. --}}
<a {{ $attributes->merge(['class' => 'eh-side__link']) }} @if($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
