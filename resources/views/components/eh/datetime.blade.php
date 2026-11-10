{{--
    Day-and-time picker drawn in the kit (resources/js/eh/datetime.js binds it): days as a row, times as a grid on a
    step-minute grid, the choice in a hidden input as local "YYYY-MM-DDTHH:MM". Replaces <input type="datetime-local">.
    The page script calls bindWhen(root, {min, max}) with its own lead-time rules.
--}}
@props(['id', 'name' => null, 'label' => 'Pickup date and time', 'step' => 15, 'value' => null])
<div {{ $attributes->class('eh-when') }} data-eh-when data-step="{{ $step }}" role="group" aria-labelledby="{{ $id }}-label">
    <p class="eh-field__label" id="{{ $id }}-label" style="margin:0">{{ $label }}</p>
    <input type="hidden" id="{{ $id }}" @if($name) name="{{ $name }}" @endif value="{{ $value }}">
    <fieldset>
        <legend class="eh-sr-only">Day</legend>
        <div class="eh-when__days" data-eh-when-days></div>
    </fieldset>
    <fieldset>
        <legend class="eh-sr-only">Time</legend>
        <div class="eh-when__times" data-eh-when-times></div>
    </fieldset>
    <p class="eh-when__summary" data-eh-when-summary aria-live="polite"></p>
</div>
