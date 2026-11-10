@props(['keys' => []])
<span {{ $attributes->merge(['style' => 'display:inline-flex;gap:4px;align-items:center']) }}>@foreach ((array) $keys as $key)<kbd class="eh-kbd">{{ $key }}</kbd>@endforeach{{ $slot }}</span>
