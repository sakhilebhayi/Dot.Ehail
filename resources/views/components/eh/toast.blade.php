{{-- A single static toast-styled notice (for server-rendered confirmations that should stay on the page). --}}
@props(['tone' => 'neutral'])
<div {{ $attributes->class('eh-toast') }} data-tone="{{ $tone }}" role="status" style="position: static; animation: none"><p class="eh-toast__text" style="margin:0">{{ $slot }}</p></div>
