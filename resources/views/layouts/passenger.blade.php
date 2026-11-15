{{--
    Passenger app shell (/ride). Mobile first: top bar (logo, safety slot, account), content, bottom tab bar on phones
    (Ride, Trips, Safety, Account: only destinations whose routes exist). Slots: safety (replaces the default safety
    control), header (page heading row). bleed: content runs edge to edge (map-first screens).
--}}
@php
    $navItems = app(\App\Support\Ui\Navigation::class)->items('passenger', auth()->user());
    $navLabel = 'Passenger';
    $appsModel = \App\Support\Ui\AppsSwitcher::model(auth()->user(), request()->route()?->getName());
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh dot-roboto" data-theme-key="eh-theme" data-dh-theme-class="dark" data-dh-theme-key="eh-theme" data-sw="on">
<head>
    @include('layouts.partials.eh-head', ['title' => $title ?? null])
    {{ $head ?? '' }}
    @vite(['resources/css/comms/comms.css'])
</head>
<body>
    <a class="eh-skip" href="#main">Skip to content</a>
    <div class="eh-app {{ ($bleed ?? false) ? 'eh-app--bleed' : '' }}" data-surface="passenger">
        @include('layouts.partials.eh-header', ['surface' => 'passenger', 'safety' => $safety ?? null, 'appsModel' => $appsModel])
        <main id="main" class="eh-app__main" tabindex="-1">
            @isset($header)<div class="eh-pagehead">{{ $header }}</div>@endisset
            {{ $slot }}
        </main>
        @include('layouts.partials.eh-tabbar')
    </div>
    <x-eh.toasts />
    @stack('modals')
</body>
</html>
