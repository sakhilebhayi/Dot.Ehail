{{--
    Bottom sheet over a map, with snap points peek | half | full (resources/js/eh/sheet.js). Non-modal: the map stays
    usable behind it. The handle is a real button (click, Enter, ArrowUp/ArrowDown, Home/End, Escape to collapse).
    On wide screens it docks as a side panel (dock=false keeps it a sheet). Without script it shows at peek height
    and its body scrolls.
--}}
@props(['id', 'title' => null, 'snap' => 'peek', 'snaps' => 'peek,half,full', 'dock' => true])
<section id="{{ $id }}" {{ $attributes->class(['eh-sheet', 'eh-sheet--dock-desktop' => $dock]) }} data-eh-sheet data-snap="{{ $snap }}" data-snaps="{{ $snaps }}" @if($title) aria-labelledby="{{ $id }}-title" @else aria-label="Panel" @endif>
    <button type="button" class="eh-sheet__handle" data-eh-sheet-handle aria-controls="{{ $id }}-body" aria-expanded="{{ $snap === 'peek' ? 'false' : 'true' }}" aria-label="Expand panel"></button>
    @if ($title)
        <header class="eh-sheet__head"><h2 class="eh-sheet__title" id="{{ $id }}-title">{{ $title }}</h2></header>
    @endif
    <div class="eh-sheet__body" id="{{ $id }}-body">{{ $slot }}</div>
</section>
