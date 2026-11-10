@props(['active'])

<a {{ $attributes->merge(['class' => 'eh-tab']) }} @if($active ?? false) aria-current="page" @endif>
    {{ $slot }}
</a>
