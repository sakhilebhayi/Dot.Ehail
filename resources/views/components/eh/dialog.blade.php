{{--
    Dialog on the native <dialog> element (focus trap, Escape and the top layer come from the browser).
    Open with any element carrying data-eh-dialog-open="{id}"; close with data-eh-dialog-close.
--}}
@props(['id', 'title'])
<dialog id="{{ $id }}" {{ $attributes->class('eh-dialog') }} aria-labelledby="{{ $id }}-title">
    <div class="eh-dialog__head">
        <h2 class="eh-dialog__title" id="{{ $id }}-title">{{ $title }}</h2>
        <x-eh.icon-button icon="close" label="Close" data-eh-dialog-close />
    </div>
    <div class="eh-dialog__body">{{ $slot }}</div>
    @isset($footer)<div class="eh-dialog__foot">{{ $footer }}</div>@endisset
</dialog>
