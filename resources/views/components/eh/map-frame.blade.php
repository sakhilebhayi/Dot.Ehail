{{--
    The container the geo map mounts in (resources/js/geo owns the map). Contract: the geo script finds
    [data-eh-map], renders into .eh-mapframe__canvas, and sets data-map-state="ready" (hides the status) or
    "unavailable" (keeps the status, with its own message in [data-eh-map-message]). Until then the frame says
    what it is waiting for; it never pretends to be a map.
--}}
@props(['id' => 'map', 'label' => 'Map', 'height' => 280, 'bleed' => false, 'message' => 'The map loads here once location is available.'])
<div {{ $attributes->class('eh-mapframe'.($bleed ? ' eh-mapframe--bleed' : '')) }} id="{{ $id }}" data-eh-map data-map-state="pending" role="region" aria-label="{{ $label }}" style="--eh-map-h: {{ is_numeric($height) ? $height.'px' : $height }}">
    <div class="eh-mapframe__canvas" data-eh-map-canvas></div>
    <p class="eh-mapframe__status" data-eh-map-message><x-eh.icon name="map" /><span>{{ $message }}</span></p>
    {{ $slot }}
</div>
