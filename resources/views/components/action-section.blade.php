<div {{ $attributes->merge(['class' => 'eh-js-section']) }}>
    <x-section-title>
        <x-slot name="title">{{ $title }}</x-slot>
        <x-slot name="description">{{ $description }}</x-slot>
    </x-section-title>

    <div class="eh-js-section__body">
        <div class="eh-js-panel">
            {{ $content }}
        </div>
    </div>
</div>
