@props(['name', 'size' => null, 'label' => null])
@php($body = \App\Support\Ui\Icons::body($name))
<svg {{ $attributes->class('eh-icon')->merge(['style' => $size ? "--eh-icon: {$size}px" : null]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" @if($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>{!! $body !!}</svg>
