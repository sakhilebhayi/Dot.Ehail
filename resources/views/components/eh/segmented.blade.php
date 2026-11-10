{{-- Segmented control: a radio group on one track. options: [value => label] or [value => [label, icon]]. --}}
@props(['legend', 'name', 'options' => [], 'value' => null, 'idPrefix' => null])
@php($prefix = $idPrefix ?? 'seg-'.\Illuminate\Support\Str::slug($name, '-'))
<fieldset {{ $attributes->class('eh-segmented') }}>
    <legend>{{ $legend }}</legend>
    @foreach ($options as $optionValue => $option)
        @php([$optionLabel, $optionIcon] = is_array($option) ? [$option[0], $option[1] ?? null] : [$option, null])
        <label class="eh-segmented__opt" for="{{ $prefix }}-{{ $optionValue }}">
            <input type="radio" id="{{ $prefix }}-{{ $optionValue }}" name="{{ $name }}" value="{{ $optionValue }}" @checked((string) old($name, $value) === (string) $optionValue) {{ $attributes->only(['data-eh-theme-choice']) }}>
            <span>@if($optionIcon)<x-eh.icon :name="$optionIcon" size="18" />@endif{{ $optionLabel }}</span>
        </label>
    @endforeach
</fieldset>
