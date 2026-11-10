{{--
    Button. variant: primary | secondary | ghost | signal | danger. size: sm (36) | md (44) | lg (48) | driver (56).
    href renders a link; loading sets aria-busy and keeps the label; disabled links get aria-disabled and no href.
--}}
@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button', 'icon' => null, 'iconRight' => null, 'loading' => false, 'disabled' => false, 'block' => false])
@php
    $classes = 'eh-btn eh-btn--'.$variant.' eh-btn--'.$size.($block ? ' eh-btn--block' : '');
@endphp
@if ($href && ! $disabled)
<a href="{{ $href }}" {{ $attributes->class($classes) }} @if($loading) aria-busy="true" @endif>
@elseif ($href)
<a {{ $attributes->class($classes) }} aria-disabled="true" role="link">
@else
<button type="{{ $type }}" {{ $attributes->class($classes) }} @disabled($disabled) @if($loading) aria-busy="true" @endif>
@endif
    @if ($loading)<span class="eh-btn__spinner" aria-hidden="true"></span>@elseif ($icon)<x-eh.icon :name="$icon" />@endif
    <span class="eh-btn__label">{{ $slot }}</span>
    @if ($iconRight)<x-eh.icon :name="$iconRight" />@endif
@if ($href)
</a>
@else
</button>
@endif
