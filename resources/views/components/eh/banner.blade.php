{{--
    Inline banner. tone: info | go | caution | stop | safety. The safety banner carries South Africa's emergency
    numbers as one-tap calls (docs/R46_ARCHITECTURE.md section 7): 10111 police, 10177 ambulance, 112 from a mobile.
--}}
@props(['tone' => 'info', 'title' => null, 'icon' => null, 'dismissible' => false, 'emergency' => null])
@php
    $icon = $icon ?? ['info' => 'info', 'go' => 'success', 'caution' => 'warning', 'stop' => 'alert', 'safety' => 'shield'][$tone] ?? 'info';
    $emergency = $emergency ?? ($tone === 'safety');
    $role = in_array($tone, ['stop', 'caution'], true) ? 'alert' : 'status';
@endphp
<div {{ $attributes->class('eh-banner eh-banner--'.$tone) }} role="{{ $role }}">
    <x-eh.icon :name="$icon" />
    <div class="eh-banner__body">
        @if ($title)<p class="eh-banner__title">{{ $title }}</p>@endif
        @if (trim($slot) !== '')<div class="eh-banner__text">{{ $slot }}</div>@endif
        @if ($emergency)
            <div class="eh-banner__actions">
                <a class="eh-call" href="tel:10111"><x-eh.icon name="phone" />Police 10111</a>
                <a class="eh-call" href="tel:10177"><x-eh.icon name="phone" />Ambulance 10177</a>
                <a class="eh-call" href="tel:112"><x-eh.icon name="phone" />112 from a mobile</a>
            </div>
        @endif
        @isset($actions)<div class="eh-banner__actions">{{ $actions }}</div>@endisset
    </div>
    @if ($dismissible)<x-eh.icon-button icon="close" label="Dismiss" onclick="this.closest('.eh-banner').remove()" />@endif
</div>
