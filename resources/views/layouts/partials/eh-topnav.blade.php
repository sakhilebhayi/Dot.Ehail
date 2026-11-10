@if (count($navItems))
<nav class="eh-topbar__nav" aria-label="{{ $navLabel }}">
    @foreach ($navItems as $item)
        <a href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif><x-eh.icon :name="$item['icon']" />{{ $item['label'] }}</a>
    @endforeach
</nav>
@endif
