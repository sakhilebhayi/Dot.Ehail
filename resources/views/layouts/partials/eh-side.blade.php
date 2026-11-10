{{--
    Side shell for desktop work surfaces (ops, business, fleet). Expects $surface, $navLabel, $scopeTitle,
    $groups (label => item keys, optional), $dense, $slot, $title, optional $header and $head.
--}}
@php
    $navItems = app(\App\Support\Ui\Navigation::class)->items($surface, auth()->user());
    $byKey = collect($navItems)->keyBy('key');
    $grouped = [];
    foreach (($groups ?? [null => array_keys($byKey->all())]) as $groupLabel => $keys) {
        $groupItems = collect($keys)->map(fn ($key) => $byKey->get($key))->filter()->values()->all();
        if ($groupItems) { $grouped[] = [$groupLabel, $groupItems]; }
    }
    $homeRoute = \App\Support\Ui\RoleHome::HOME_ROUTES[$surface];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh" data-theme-key="eh-theme" data-sw="on">
<head>
    @include('layouts.partials.eh-head', ['title' => $title ?? null])
    {{ $head ?? '' }}
</head>
<body>
    <a class="eh-skip" href="#main">Skip to content</a>
    <div class="eh-side {{ ($dense ?? false) ? 'eh-side--dense' : '' }}" data-surface="{{ $surface }}">
        <header class="eh-side__bar" data-eh-drawer-behind>
            <x-eh.icon-button icon="menu" label="Open navigation" data-eh-drawer-open aria-controls="eh-side-nav" aria-expanded="false" />
            <x-eh.logo :href="Route::has($homeRoute) ? route($homeRoute) : url('/')" />
            @include('layouts.partials.eh-account', ['surface' => $surface])
        </header>
        <div class="eh-side__scrim" data-eh-drawer-close aria-hidden="true"></div>
        <nav class="eh-side__nav" id="eh-side-nav" aria-label="{{ $navLabel }}">
            <div class="eh-side__head">
                <x-eh.logo :href="Route::has($homeRoute) ? route($homeRoute) : url('/')" />
                <x-eh.icon-button class="eh-side__close" icon="close" label="Close navigation" data-eh-drawer-close />
            </div>
            <p class="eh-side__scope" style="margin:0"><strong>{{ $scopeTitle }}</strong>{{ $scopeSub ?? '' }}</p>
            @foreach ($grouped as [$groupLabel, $groupItems])
                <div class="eh-side__group">
                    @if ($groupLabel)<p class="eh-side__label" style="margin:0">{{ $groupLabel }}</p>@endif
                    @foreach ($groupItems as $item)
                        <a class="eh-side__link" href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif><x-eh.icon :name="$item['icon']" />{{ $item['label'] }}</a>
                    @endforeach
                </div>
            @endforeach
            <div class="eh-side__foot">
                @foreach (app(\App\Support\Ui\Navigation::class)->switches($surface, auth()->user()) as $switch)
                    <a class="eh-side__link" href="{{ $switch['url'] }}"><x-eh.icon name="arrow-right" />{{ $switch['label'] }}</a>
                @endforeach
                <a class="eh-side__link" href="{{ route('profile.show') }}"><x-eh.icon name="account" />Profile and security</a>
                <form method="POST" action="{{ route('logout') }}" style="margin:0">
                    @csrf
                    <button type="submit" class="eh-side__link" style="width:100%;border:0;background:none;cursor:pointer;font:inherit"><x-eh.icon name="sign-out" />Sign out</button>
                </form>
            </div>
        </nav>
        <main id="main" class="eh-side__main" tabindex="-1" data-eh-drawer-behind>
            <div class="eh-side__inner">
                @isset($header)<div class="eh-pagehead">{{ $header }}</div>@endisset
                {{ $slot }}
            </div>
        </main>
    </div>
    <x-eh.toasts />
    @stack('modals')
</body>
</html>
