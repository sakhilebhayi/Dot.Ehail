{{--
    Tabs. With hrefs, a navigation row (aria-current on the active link). Without hrefs, an ARIA tablist whose
    panels are <x-eh.tab-panel id="..."> elements; keys: [key => label] and active key.
--}}
@props(['items' => [], 'active' => null, 'label' => 'Sections', 'id' => 'tabs'])
@php($isNav = collect($items)->contains(fn ($item) => is_array($item) && isset($item['href'])))
@if ($isNav)
<nav {{ $attributes->class('eh-tabs') }} aria-label="{{ $label }}">
    @foreach ($items as $key => $item)
        <a class="eh-tab" href="{{ $item['href'] }}" @if($key === $active) aria-current="page" @endif>{{ $item['label'] }}</a>
    @endforeach
</nav>
@else
<div {{ $attributes->class('eh-tabs') }} role="tablist" aria-label="{{ $label }}" data-eh-tabs>
    @foreach ($items as $key => $item)
        @php($text = is_array($item) ? $item['label'] : $item)
        <button type="button" role="tab" class="eh-tab" id="{{ $id }}-tab-{{ $key }}" aria-controls="{{ $id }}-panel-{{ $key }}" aria-selected="{{ $key === $active ? 'true' : 'false' }}" tabindex="{{ $key === $active ? '0' : '-1' }}">{{ $text }}</button>
    @endforeach
</div>
@endif
