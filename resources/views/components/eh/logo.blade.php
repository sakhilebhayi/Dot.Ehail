{{-- Logo: vector redraw of public/images/logo.png (yellow disc carrying the car, green chevron) with the wordmark. --}}
@props(['href' => null, 'size' => 32, 'word' => true, 'label' => 'Dot.Ehail'])
@php($tag = $href ? 'a' : 'span')
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->class('eh-logo') }} style="--eh-logo: {{ (int) $size }}px" @if($href) aria-label="{{ $label }} home" @endif>
    <svg class="eh-logo__mark" viewBox="0 0 64 44" aria-hidden="true" focusable="false">
        <circle class="eh-logo__disc" cx="22" cy="22" r="21"/>
        <path class="eh-logo__car" d="M9.5 25.3v-2.6c0-.7.5-1.2 1.1-1.3l3.1-.7 3-2.7c.6-.5 1.3-.8 2.1-.8h5.4c.8 0 1.6.3 2.2.9l2.4 2.4 3.7.7c.9.2 1.5.9 1.5 1.8v2.3c0 .4-.3.7-.7.7h-1.4a3 3 0 0 0-5.8 0h-7.2a3 3 0 0 0-5.8 0h-.9c-.4 0-.7-.3-.7-.6Zm7.9-4.2h4.2v-2.6h-2c-.4 0-.7.1-1 .4l-1.2 2.2Zm5.6 0h4.6l-1.7-1.8c-.3-.3-.6-.4-1-.4H23v2.2Z"/>
        <circle class="eh-logo__car" cx="16.4" cy="26.6" r="2.2"/><circle class="eh-logo__car" cx="28.4" cy="26.6" r="2.2"/>
        <path class="eh-logo__chevron" d="M46 7l14 15-14 15" fill="none" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    @if ($word)<span class="eh-logo__word">Dot<span>.</span>Ehail</span>@endif
</{{ $tag }}>
