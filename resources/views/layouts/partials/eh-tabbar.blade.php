@if (count($navItems))
<nav class="eh-tabbar" aria-label="{{ $navLabel }}">
    @foreach ($navItems as $item)
        <a class="eh-tabbar__item" href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif>
            <span class="eh-tabbar__pill"><x-eh.icon :name="$item['icon']" /></span>{{ $item['label'] }}
        </a>
    @endforeach
</nav>
@endif
