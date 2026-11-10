@props(['id' => null, 'maxWidth' => null])

<x-modal :id="$id" :maxWidth="$maxWidth" {{ $attributes }}>
    <div class="eh-dialog__head" style="padding-bottom:0">
        <span class="eh-row__icon" style="background:var(--eh-stop-bg);color:var(--eh-stop)"><x-eh.icon name="warning" /></span>
        <h2 class="eh-dialog__title">{{ $title }}</h2>
    </div>
    <div class="eh-dialog__body">
        {{ $content }}
    </div>
    <div class="eh-dialog__foot">
        {{ $footer }}
    </div>
</x-modal>
