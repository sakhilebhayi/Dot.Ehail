{{-- Chip: a toggle (pressed state) or a filter link (current). --}}
@props(['pressed' => null, 'href' => null, 'current' => false, 'icon' => null])
@if ($href)
<a href="{{ $href }}" {{ $attributes->class('eh-chip') }} @if($current) aria-current="true" @endif>@if($icon)<x-eh.icon :name="$icon" />@endif{{ $slot }}</a>
@else
<button type="button" {{ $attributes->class('eh-chip') }} @if(! is_null($pressed)) aria-pressed="{{ $pressed ? 'true' : 'false' }}" @endif>@if($icon)<x-eh.icon :name="$icon" />@endif{{ $slot }}</button>
@endif
