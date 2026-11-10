{{-- Labelled native select. options: [value => label]. --}}
@props(['label', 'name', 'options' => [], 'value' => null, 'id' => null, 'placeholder' => null, 'hint' => null, 'error' => null])
@php
    $id = $id ?? 'f-'.\Illuminate\Support\Str::slug($name, '-');
    $message = $error ?? (($errors ?? new \Illuminate\Support\ViewErrorBag)->first($name) ?: null);
    $selected = (string) old($name, $value);
    $describedBy = trim(($hint ? $id.'-hint ' : '').($message ? $id.'-error' : ''));
@endphp
<div class="eh-field">
    <label class="eh-field__label" for="{{ $id }}">{{ $label }}</label>
    @if ($hint)<p class="eh-field__hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
    <div class="eh-select-wrap">
        <select id="{{ $id }}" name="{{ $name }}" {{ $attributes->class('eh-select') }} @if($describedBy) aria-describedby="{{ $describedBy }}" @endif @if($message) aria-invalid="true" @endif>
            @if ($placeholder)<option value="" @selected($selected === '')>{{ $placeholder }}</option>@endif
            @foreach ($options as $optionValue => $optionLabel)
                <option value="{{ $optionValue }}" @selected($selected === (string) $optionValue)>{{ $optionLabel }}</option>
            @endforeach
        </select>
        <x-eh.icon name="chevron-down" />
    </div>
    @if ($message)<p class="eh-field__error" id="{{ $id }}-error"><x-eh.icon name="alert" />{{ $message }}</p>@endif
</div>
