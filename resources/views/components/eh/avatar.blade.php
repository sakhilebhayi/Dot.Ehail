{{-- Avatar: initials on a pale identity tint derived from the name (stable per person). Images only from this host. --}}
@props(['name', 'size' => 40, 'src' => null])
@php
    $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
    $initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    $hue = crc32((string) $name) % 360;
    $local = $src && ! preg_match('#^(https?:)?//#i', $src);
@endphp
<span {{ $attributes->class('eh-avatar') }} style="--eh-avatar: {{ (int) $size }}px; --eh-avatar-hue: {{ $hue }}" aria-hidden="true">@if($local)<img src="{{ $src }}" alt="">@else{{ $initials }}@endif</span>
