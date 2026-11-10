{{-- Switch: a checkbox with role="switch", a visible track in both states and an On/Off word so state is not colour alone. --}}
@props(['label', 'name', 'checked' => false, 'description' => null, 'id' => null, 'value' => '1'])
@php($id = $id ?? 'sw-'.\Illuminate\Support\Str::slug($name, '-'))
<label class="eh-switch" for="{{ $id }}">
    <input type="checkbox" role="switch" class="eh-switch__input" id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" @checked(old($name, $checked)) {{ $attributes }} @if($description) aria-describedby="{{ $id }}-desc" @endif>
    <span class="eh-switch__track" aria-hidden="true"></span>
    <span class="eh-switch__text">
        <span class="eh-switch__label">{{ $label }}</span>
        @if ($description)<span class="eh-switch__desc" id="{{ $id }}-desc">{{ $description }}</span>@endif
    </span>
</label>
