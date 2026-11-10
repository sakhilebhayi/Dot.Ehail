@props(['tabs' => 'tabs', 'key', 'active' => false])
<div {{ $attributes->class('eh-tabpanel') }} role="tabpanel" id="{{ $tabs }}-panel-{{ $key }}" aria-labelledby="{{ $tabs }}-tab-{{ $key }}" tabindex="0" @unless($active) hidden @endunless>{{ $slot }}</div>
