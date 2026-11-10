{{-- Avatar: initials on a pale identity tint derived from the name (stable per person). Images only from this host.
     `mark`: the initials are drawn by CSS from data-initials, not page text. Use it where the avatar is the only
     visible content of a named control (the account menu): the control's name ("Account menu for ...") then holds
     all of its text, so speech-input users are not told to say initials (WCAG 2.5.3, R-46 a11y D-1709). --}}
@props(['name', 'size' => 40, 'src' => null, 'mark' => false])
@php
    $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
    $initials = mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
    $hue = [150, 170, 95, 45, 200, 125][crc32((string) $name) % 6]; // greens, olive, road yellow, map water
    $local = $src && ! preg_match('#^(https?:)?//#i', $src);
@endphp
<span {{ $attributes->class('eh-avatar') }} style="--eh-avatar: {{ (int) $size }}px; --eh-avatar-hue: {{ $hue }}" aria-hidden="true" @if($mark && ! $local) data-initials="{{ $initials }}" @endif>@if($local)<img src="{{ $src }}" alt="">@elseif(! $mark){{ $initials }}@endif</span>
