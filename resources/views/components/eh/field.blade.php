{{--
    Labelled text field. type: text | email | password | tel | number | search | url | date | time | textarea.
    The error comes from the validation bag by name unless given. Hint and error are tied to the input with aria-describedby.
--}}
@props(['label', 'name', 'type' => 'text', 'id' => null, 'value' => null, 'hint' => null, 'error' => null, 'optional' => false, 'size' => 'md', 'bag' => 'default'])
@php
    $id = $id ?? 'f-'.\Illuminate\Support\Str::slug(str_replace(['[', ']', '.'], '-', $name), '-');
    $message = $error ?? (($errors ?? new \Illuminate\Support\ViewErrorBag)->getBag($bag)->first(str_replace(['[', ']'], ['.', ''], $name)) ?: null);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($message ? $id.'-error' : ''));
    $old = $type === 'password' ? null : old($name, $value);
    $inputClass = $type === 'textarea' ? 'eh-textarea' : 'eh-input'.($size === 'driver' ? ' eh-input--driver' : '');
@endphp
<div class="eh-field">
    <label class="eh-field__label" for="{{ $id }}">{{ $label }}@if($optional) <span class="eh-field__optional">(optional)</span>@endif</label>
    @if ($hint)<p class="eh-field__hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
    @if (trim($slot) !== '')
        {{ $slot }}
    @elseif ($type === 'textarea')
        <textarea id="{{ $id }}" name="{{ $name }}" {{ $attributes->class($inputClass) }} @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif @if(! $optional && $attributes->has('required')) aria-required="true" @endif>{{ $old }}</textarea>
    @else
        <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" @if($old !== null) value="{{ $old }}" @endif {{ $attributes->class($inputClass) }} @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif>
    @endif
    @if ($message)<p class="eh-field__error" id="{{ $id }}-error"><x-eh.icon name="alert" />{{ $message }}</p>@endif
</div>
