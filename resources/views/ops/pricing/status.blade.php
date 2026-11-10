@php
    $live = $v->status === 'published' && $v->retired_at === null;
    $future = $live && $v->effective_from && $v->effective_from->isFuture();
@endphp
@if ($v->status === 'draft')<x-eh.badge tone="info">Draft</x-eh.badge>
@elseif ($future)<x-eh.badge tone="caution">Starts {{ $v->effective_from->copy()->setTimezone(\App\Support\Ui\LocalTime::timezone())->format('d M H:i') }}</x-eh.badge>
@elseif ($live)<x-eh.badge tone="go">Live</x-eh.badge>
@else<x-eh.badge>Retired</x-eh.badge>@endif
@if ($v->is_example)<x-eh.badge tone="caution" icon="warning">Example, review before launch</x-eh.badge>@endif
