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
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh" data-theme-key="eh-theme" data-sw="on">
<head>
    @include('layouts.partials.eh-head', ['title' => $title ?? null])
    {{ $head ?? '' }}
    @vite(['resources/css/comms/comms.css'])
</head>
<body>
    <a class="eh-skip" href="#main">Skip to content</a>
    <div class="eh-app {{ ($bleed ?? false) ? 'eh-app--bleed' : '' }}" data-surface="passenger">
        <header class="eh-topbar">
            <div class="eh-topbar__brand"><x-eh.logo :href="Route::has('ride.home') ? route('ride.home') : url('/')" /></div>
            @include('layouts.partials.eh-topnav')
            <div class="eh-topbar__apps">@include('layouts.partials.eh-apps', ['appsModel' => $appsModel])</div>
            <div class="eh-topbar__slot">
                @isset($safety){{ $safety }}@else @include('layouts.partials.eh-safety', ['safetyRoute' => 'ride.safety']) @endisset
                <x-comms::bell surface="passenger" />
                @include('layouts.partials.eh-account', ['surface' => 'passenger'])
            </div>
        </header>
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
