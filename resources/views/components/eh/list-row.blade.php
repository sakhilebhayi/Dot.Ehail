{{-- One row in an <ul class="eh-list">. href makes the whole row a link; trailing content goes in the "trail" slot. --}}
@props(['title', 'meta' => null, 'icon' => null, 'href' => null, 'chevron' => null])
@php($tag = $href ? 'a' : 'div')
<li>
    <{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->class('eh-row') }}>
        @if (isset($lead)){{ $lead }}@elseif ($icon)<span class="eh-row__icon"><x-eh.icon :name="$icon" /></span>@endif
        <span class="eh-row__main">
            <span class="eh-row__title">{{ $title }}</span>
            @if ($meta)<span class="eh-row__meta">{{ $meta }}</span>@endif
        </span>
        @if (isset($trail) || ($chevron ?? $href))
            <span class="eh-row__trail">{{ $trail ?? '' }}@if($chevron ?? $href)<x-eh.icon name="chevron-right" />@endif</span>
        @endif
    </{{ $tag }}>
</li>
