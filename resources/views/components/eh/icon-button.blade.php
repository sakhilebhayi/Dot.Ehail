{{-- Icon-only button. label is required (aria-label and tooltip). variant: ghost | secondary | primary | danger. size: md (44) | driver (56). --}}
@props(['icon', 'label', 'variant' => 'ghost', 'size' => 'md', 'href' => null, 'type' => 'button'])
@php($classes = 'eh-iconbtn eh-iconbtn--'.$variant.($size === 'driver' ? ' eh-iconbtn--driver' : ''))
@if ($href)
<a href="{{ $href }}" {{ $attributes->class($classes) }} aria-label="{{ $label }}" title="{{ $label }}"><x-eh.icon :name="$icon" /></a>
@else
<button type="{{ $type }}" {{ $attributes->class($classes) }} aria-label="{{ $label }}" title="{{ $label }}"><x-eh.icon :name="$icon" /></button>
@endif
