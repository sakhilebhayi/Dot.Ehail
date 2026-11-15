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
    $appsModel = \App\Support\Ui\AppsSwitcher::model(auth()->user(), request()->route()?->getName());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh dot-roboto" data-theme-key="eh-theme" data-dh-theme-class="dark" data-dh-theme-key="eh-theme" data-sw="on">
<head>
    @include('layouts.partials.eh-head', ['title' => $title ?? null])
    {{ $head ?? '' }}
</head>
<body>
    <a class="eh-skip" href="#main">Skip to content</a>
    <div class="eh-side {{ ($dense ?? false) ? 'eh-side--dense' : '' }}" data-surface="{{ $surface }}">
        {{-- R-52: the shared header on top of every side shell, at every width. The sidebar below is its menu (adopt mode): the menu button
             opens it under 861px, and from 861px it stays beside the page. --}}
        @include('layouts.partials.eh-header', ['surface' => $surface, 'adopt' => 'eh-side-nav', 'appsModel' => $appsModel, 'context' => $scopeTitle, 'navLabel' => $navLabel])
        <nav class="eh-side__nav" id="eh-side-nav" aria-label="{{ $navLabel }} menu">
            <div class="eh-side__head">
                <x-eh.logo :href="Route::has($homeRoute) ? route($homeRoute) : url('/')" />
                <x-eh.icon-button class="eh-side__close" icon="close" label="Close navigation" data-dh-close />
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
        </nav>
        <main id="main" class="eh-side__main" tabindex="-1">
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
