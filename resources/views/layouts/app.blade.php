<!DOCTYPE html>
{{--
    Legacy app shell for the pre-R-46 pages (rides, driver desk, operator pages, profile and teams) until wave 2
    replaces them with the passenger, driver and ops shells. Restyled in Dot.Ehail tokens; no third-party hosts.
    The old ride and operator pages are written with fixed dark inline styles, so this shell pins the dark theme
    for them; the account pages (profile, teams, API tokens) follow the person's theme.
--}}
@php
    $legacyPinDark = ! request()->routeIs('profile.*', 'teams.*', 'api-tokens.*');
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh eh-legacy {{ $legacyPinDark ? 'dark' : '' }}" @if($legacyPinDark) data-theme="dark" data-theme-lock @endif data-theme-key="eh-theme">
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
            <button type="button" class="shell-close" data-shell-close aria-label="Close menu">
                <x-eh.icon name="close" />
            </button>
        </div>

        <div class="sidebar-divider"></div>

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
            <div class="sidebar-divider"></div>
            <a href="{{ route('profile.show') }}" class="nav-item {{ request()->routeIs('profile.show') ? 'active' : '' }}">
                <x-eh.icon name="account" class="nav-icon" />
                Profile & Settings
            </a>
        </nav>

        @auth
        <div class="sidebar-footer">
            <div class="user-row">
                <x-eh.avatar :name="Auth::user()->name" size="32" />
                <div style="min-width:0;flex:1;">
                    <div class="user-name">{{ Auth::user()->name }}</div>
                    <div class="user-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="topbar-btn" title="Sign out" aria-label="Sign out">
                        <x-eh.icon name="sign-out" />
                    </button>
                </form>
            </div>
            @if (Laravel\Jetstream\Jetstream::hasTeamFeatures() && Auth::user()->currentTeam)
            <details class="team-switch">
                <summary>
                    <x-eh.icon name="users" class="nav-icon" />
                    <span class="team-switch-name">Teams</span>
                    <x-eh.icon name="chevron-down" class="team-switch-chevron" />
                </summary>
                <div class="team-switch-list">
                    <a href="{{ route('teams.show', Auth::user()->currentTeam->id) }}" class="nav-item {{ request()->routeIs('teams.show') ? 'active' : '' }}">Team settings</a>
                    @can('create', Laravel\Jetstream\Jetstream::newTeamModel())
                    <a href="{{ route('teams.create') }}" class="nav-item {{ request()->routeIs('teams.create') ? 'active' : '' }}">Create new team</a>
                    @endcan
                    @if (Auth::user()->allTeams()->count() > 1)
                    <div class="nav-section-label">Switch teams</div>
                    @foreach (Auth::user()->allTeams() as $switchTeam)
                    <form method="POST" action="{{ route('current-team.update') }}">
                        @method('PUT')
                        @csrf
                        <input type="hidden" name="team_id" value="{{ $switchTeam->id }}">
                        <button type="submit" class="nav-item {{ Auth::user()->isCurrentTeam($switchTeam) ? 'active' : '' }}" @if (Auth::user()->isCurrentTeam($switchTeam)) aria-current="true" @endif>{{ $switchTeam->name }}</button>
                    </form>
                    @endforeach
                    @endif
                </div>
            </details>
            @endif
        </div>
        @endauth
    </aside>

    <div class="shell-backdrop" data-shell-close aria-hidden="true"></div>

    <header class="topbar" data-shell-behind>
        <button type="button" class="shell-menu-btn" data-shell-open aria-controls="app-sidebar" aria-expanded="false">
            <x-eh.icon name="menu" />
            <span>Menu</span>
        </button>
        <div class="topbar-title">
            @isset($header){{ $header }}@else Dot.Ehail
            @endisset
        </div>
        @auth
        <span class="topbar-team">{{ Auth::user()->currentTeam->name ?? 'Personal' }}</span>
        <livewire:notification-bell />
        @endauth
                <a href="{{ route('profile.show') }}" class="topbar-btn topbar-profile" title="Profile" aria-label="Profile">
            <x-eh.icon name="account" />
        </a>
    </header>

    <div class="shell-legacy-nav">
        @livewire('navigation-menu')
    </div>

    <div class="content-wrap" data-shell-behind>
        <x-banner />
        <main id="main-content" tabindex="-1">{{ $slot }}</main>
        <x-eh.toasts />
    </div>

    @stack('modals')
    @include('layouts.partials.shell-script')
    @livewireScripts
</body>
</html>
