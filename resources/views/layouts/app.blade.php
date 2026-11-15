<!DOCTYPE html>
{{--
    Legacy app shell for the pre-R-46 pages (rides, driver desk, operator pages, profile and teams) until wave 2
    replaces them with the passenger, driver and ops shells. Restyled in Dot.Ehail tokens; no third-party hosts.
    The old ride and operator pages are written with fixed dark inline styles, so this shell pins the dark theme
    for them; the landing (dashboard, rebuilt in tokens for R-50) and the account pages (profile, teams, API tokens)
    follow the person's theme.
--}}
@php
    $legacyPinDark = ! request()->routeIs('dashboard', 'profile.*', 'teams.*', 'api-tokens.*');
    $appsModel = \App\Support\Ui\AppsSwitcher::model(auth()->user(), request()->route()?->getName());
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh dot-roboto eh-legacy {{ $legacyPinDark ? 'dark' : '' }}" @if($legacyPinDark) data-theme="dark" data-theme-lock @endif data-theme-key="eh-theme" data-dh-theme-class="dark" data-dh-theme-key="eh-theme">
<head>
    @include('layouts.partials.eh-head', ['title' => null, 'viteEntries' => ['resources/css/app.css', 'resources/js/app.js']])
    @livewireStyles
    {{-- No stand-alone Alpine here: Livewire 3 bundles and starts its own (@livewireScripts). A second copy
         replaces window.Alpine and breaks every Livewire re-render ('Alpine.cloneNode is not a function'). --}}
</head>
<body>
    <a class="eh-skip" href="#main-content">Skip to content</a>
    <aside class="sidebar" id="app-sidebar" aria-label="Main navigation">
        <div class="sidebar-brand">
            <x-eh.logo :href="route('home')" />
            <button type="button" class="shell-close" data-dh-close aria-label="Close menu">
                <x-eh.icon name="close" />
            </button>
        </div>

        <nav class="sidebar-nav">
            <a href="{{ route('dashboard') }}" class="nav-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                <x-eh.icon name="dashboard" class="nav-icon" />
                Dashboard
            </a>
            {{-- Ride request and the driver desk used to be linked only from the Jetstream
                 navigation-menu, which sits under the fixed top bar and cannot be reached. --}}
            @php
                $shellDriverProfile = \App\Models\DriverProfile::withoutGlobalScope('user')->where('user_id', Auth::id())->first();
            @endphp
            <a href="{{ route('rides.index') }}" class="nav-item {{ request()->routeIs('rides.index', 'rides.show') ? 'active' : '' }}">
                <x-eh.icon name="car" class="nav-icon" />
                Rides
            </a>
            <a href="{{ route('rides.create') }}" class="nav-item {{ request()->routeIs('rides.create') ? 'active' : '' }}">
                <x-eh.icon name="pickup" class="nav-icon" />
                Request a ride
            </a>
            @if($shellDriverProfile)
            <div class="nav-section-label">Driving</div>
            <a href="{{ route('rides.available') }}" class="nav-item {{ request()->routeIs('rides.available') ? 'active' : '' }}">
                <x-eh.icon name="list" class="nav-icon" />
                Available rides
            </a>
            <a href="{{ route('drivers.vehicles.index') }}" class="nav-item {{ request()->routeIs('drivers.vehicles.*') ? 'active' : '' }}">
                <x-eh.icon name="car" class="nav-icon" />
                My vehicles
            </a>
            <a href="{{ route('drivers.show', $shellDriverProfile) }}" class="nav-item {{ request()->routeIs('drivers.show') ? 'active' : '' }}">
                <x-eh.icon name="id" class="nav-icon" />
                My driver profile
            </a>
            @else
            <a href="{{ route('drive.apply') }}" class="nav-item {{ request()->routeIs('drive.apply') ? 'active' : '' }}">
                <x-eh.icon name="steering-wheel" class="nav-icon" />
                Drive with us
            </a>
            @endif
            @if(Auth::user()->isPlatformOperator())
            <div class="nav-section-label">Operator</div>
            <a href="{{ route('operator.rides.index') }}" class="nav-item {{ request()->routeIs('operator.rides.*') ? 'active' : '' }}">
                <x-eh.icon name="route" class="nav-icon" />
                Ride operations
            </a>
            <a href="{{ route('operator.fleets.index') }}" class="nav-item {{ request()->routeIs('operator.fleets.*') ? 'active' : '' }}">
                <x-eh.icon name="fleet" class="nav-icon" />
                Fleet review
            </a>
            @endif
        </nav>

    </aside>

    {{-- R-52: the shared header on top; the sidebar is its menu (adopt mode): dot-ui opens it, draws the scrim, locks the scroll and makes the page behind inert. --}}
    @include('layouts.partials.eh-header', ['surface' => 'legacy', 'adopt' => 'app-sidebar', 'appsModel' => $appsModel, 'theme' => ! $legacyPinDark])

    <div class="shell-legacy-nav">
        @livewire('navigation-menu')
    </div>

    <div class="content-wrap">
        <x-banner />
        <main id="main-content" tabindex="-1">
            {{-- The page's own title used to sit in the top bar; the shared header has no title, so it heads the page. --}}
            @isset($header)<div class="eh-pagehead eh-legacy-pagehead">{{ $header }}</div>@endisset
            {{ $slot }}
        </main>
        <x-eh.toasts />
    </div>

    @stack('modals')
    @livewireScripts
</body>
</html>
