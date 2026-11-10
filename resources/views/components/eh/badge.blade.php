{{-- Badge. tone: neutral | go | caution | stop | info | accent | signal. Carries a word (and optionally an icon), never colour alone. --}}
@props(['tone' => 'neutral', 'icon' => null])
<span {{ $attributes->class('eh-badge eh-badge--'.$tone) }}>@if($icon)<x-eh.icon :name="$icon" />@endif{{ $slot }}</span>
