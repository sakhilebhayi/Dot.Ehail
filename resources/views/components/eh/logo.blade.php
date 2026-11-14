{{-- Logo: the ORIGINAL files, never redrawn (public/images/logo.png, logo-light.png for dark backgrounds). Only size,
     placement and clear space are presentation. size = rendered height in px; tone = auto (follows the theme), day or night. --}}
@props(['href' => null, 'size' => 72, 'word' => true, 'label' => 'Dot.Ehail', 'tone' => 'auto'])
@php($tag = $href ? 'a' : 'span')
@php($h = (int) $size)
@php($w = (int) round($h * 1181 / 886))
<{{ $tag }} @if($href) href="{{ $href }}" aria-label="{{ $label }} home" @endif {{ $attributes->class(['eh-logo', 'eh-logo--'.$tone]) }} style="--eh-logo: {{ $h }}px">
    @if ($tone !== 'night')<img class="eh-logo__img eh-logo__img--day" src="{{ asset('images/logo.png') }}" alt="Dot.Ehail" width="{{ $w }}" height="{{ $h }}">@endif
    @if ($tone !== 'day')<img class="eh-logo__img eh-logo__img--night" src="{{ asset('images/logo-light.png') }}" alt="{{ $tone === 'night' ? 'Dot.Ehail' : '' }}" @if($tone !== 'night') aria-hidden="true" @endif width="{{ $w }}" height="{{ $h }}">@endif
</{{ $tag }}>
