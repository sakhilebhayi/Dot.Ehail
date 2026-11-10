{{-- Rating display (0 to 5). Shows the number too; never an input. count: number of ratings behind it (real only). --}}
@props(['value', 'count' => null, 'showValue' => true])
@php
    $rating = max(0, min(5, (float) $value));
    $percent = round($rating / 5 * 100, 1);
    $label = 'Rated '.number_format($rating, 1).' out of 5'.($count !== null ? ' from '.$count.' '.($count === 1 ? 'rating' : 'ratings') : '');
@endphp
<span {{ $attributes->class('eh-stars') }} role="img" aria-label="{{ $label }}">
    <span class="eh-stars__track" aria-hidden="true">
        @for ($i = 0; $i < 5; $i++)<x-eh.icon name="star" size="18" />@endfor
        <span class="eh-stars__fill" style="width: {{ $percent }}%">@for ($i = 0; $i < 5; $i++)<x-eh.icon name="star" size="18" />@endfor</span>
    </span>
    @if ($showValue)<span class="eh-stars__value" aria-hidden="true">{{ number_format($rating, 1) }}</span>@endif
    @if ($count !== null)<span class="eh-stars__count" aria-hidden="true">({{ $count }})</span>@endif
</span>
