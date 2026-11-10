{{-- Loading placeholder. lines: number of text bars; block: a height in px for a map or image region. --}}
@props(['lines' => 3, 'block' => null, 'label' => 'Loading'])
<div {{ $attributes->class('eh-skeleton') }} role="status" aria-live="polite">
    <span class="eh-sr-only">{{ $label }}</span>
    @if ($block)<div class="eh-skeleton__block" style="height: {{ (int) $block }}px" aria-hidden="true"></div>@endif
    @for ($i = 0; $i < $lines; $i++)
        <div class="eh-skeleton__bar {{ $i === 0 ? 'eh-skeleton__bar--title' : ($i === $lines - 1 ? 'eh-skeleton__bar--short' : '') }}" aria-hidden="true"></div>
    @endfor
</div>
