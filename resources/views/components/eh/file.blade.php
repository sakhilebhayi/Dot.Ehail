{{--
    File picker drawn in the kit: a dashed drop area with an icon, the action in words and the chosen file's name,
    over a real <input type="file"> (keyboard, screen readers and the phone's camera and files sheet all work as
    usual). resources/js/eh/shell.js shows the chosen name; without script the browser's own name line is hidden but
    the file still uploads.
--}}
@props(['id', 'name', 'label', 'accept' => null, 'hint' => null, 'optional' => false, 'action' => 'Choose a photo or PDF'])
<div {{ $attributes->class('eh-file') }} data-eh-file>
    <span class="eh-field__label" id="{{ $id }}-label">{{ $label }}@if($optional) <span class="eh-field__optional">optional</span>@endif</span>
    <label class="eh-file__pick" for="{{ $id }}">
        <x-eh.icon name="upload" />
        <span class="eh-file__text">
            <span class="eh-file__action">{{ $action }}</span>
            <span class="eh-file__name" data-eh-file-name>No file chosen</span>
        </span>
        <input id="{{ $id }}" name="{{ $name }}" type="file" @if($accept) accept="{{ $accept }}" @endif aria-labelledby="{{ $id }}-label" @if($hint) aria-describedby="{{ $id }}-hint" @endif>
    </label>
    @if($hint)<p class="eh-field__hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
</div>
