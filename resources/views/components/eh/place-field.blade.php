{{--
    Place search field (geo's combobox, resources/js/geo/search.js): one field with its search affordance inside it,
    a clear button while there is text, the hint, the result list and the status line. Search runs as you type where
    the geocoder allows it, otherwise on Enter, on the search button, or once typing pauses (D-2001).
    size: md (44) | lg (56, the booking sheet's "Where to?"). dot: pickup | dropoff | null (the stop marker before the label).
    Attributes go on the wrapper (data-pax-search="pickup" and the like).
--}}
@props(['id', 'label', 'placeholder' => '', 'size' => 'md', 'dot' => null, 'srLabel' => false, 'value' => null])
<div {{ $attributes->class(['eh-place', 'eh-place--lg' => $size === 'lg']) }} data-geo-search>
    <label class="eh-place__label {{ $srLabel ? 'eh-sr-only' : '' }}" for="{{ $id }}">@if($dot)<span class="eh-place__dot eh-place__dot--{{ $dot }}" aria-hidden="true"></span>@endif{{ $label }}</label>
    <div class="eh-place__box">
        <x-eh.icon name="search" class="eh-place__lead" />
        <input id="{{ $id }}" class="eh-input eh-place__input" type="search" placeholder="{{ $placeholder }}" enterkeyhint="search" spellcheck="false" @if($value) value="{{ $value }}" @endif>
        <button type="button" class="eh-place__clear" data-eh-place-clear aria-label="Clear {{ strtolower($label) }}" hidden><x-eh.icon name="close" /></button>
        <button type="button" class="eh-place__go" data-geo-search-submit aria-label="Search for {{ strtolower($label) }}"><x-eh.icon name="arrow-right" /></button>
    </div>
    <p class="eh-place__hint" data-geo-search-hint></p>
    <ul class="eh-place__results" data-geo-search-list></ul>
    <p class="eh-place__status" data-geo-search-status></p>
</div>
