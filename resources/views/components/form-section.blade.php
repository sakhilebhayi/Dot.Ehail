@props(['submit'])

<div {{ $attributes->merge(['class' => 'eh-js-section']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="eh-js-section__body">
        <form wire:submit="{{ $submit }}">
            <div class="eh-js-panel {{ isset($actions) ? 'eh-js-panel--with-actions' : '' }}">
                <div class="eh-js-form">
                    {{ $form }}
                </div>
            </div>

            @if (isset($actions))
                <div class="eh-js-actions">
                    {{ $actions }}
                </div>
            @endif
        </form>
    </div>
</div>
