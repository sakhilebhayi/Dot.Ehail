@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="eh-dialog__head" style="padding-bottom:0">
        <h2 class="eh-dialog__title">{{ $title }}</h2>
    </div>
    <div class="eh-dialog__body">
        {{ $content }}
    </div>
    <div class="eh-dialog__foot">
        {{ $footer }}
    </div>
</x-modal>
