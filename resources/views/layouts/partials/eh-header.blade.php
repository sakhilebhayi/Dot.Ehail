{{--
    The shared Dot header (dot-ui 0.6.0) for every signed-in Dot.Ehail shell (R-52). One header, five shells:

      passenger, driver   the top bar replaces the old bar; the bottom tab bar stays on phones; the menu button opens dot-ui's drawer
      ops, business, fleet the side nav is the menu: `adopt` names it, so the menu button opens that sidebar (no second drawer)
      legacy              the pre-R-46 pages (dashboard, rides, drivers, profile, teams): the sidebar is adopted too

    Variables: $surface (passenger|driver|ops|business|fleet|legacy), $adopt (the sidebar's id, or null), $safety (HtmlString|null, the passenger and
    driver shells' own safety control), $appsModel (AppsSwitcher::model, built once by the shell), $context
    (a short readout of the scope: "Operations", the fleet name), $theme (false when the page pins its theme).

    A live trip screen (AppsSwitcher::isLive) makes this header cache-only for the switcher exactly as before: the model is passed in, never built here.
--}}
@php
    $headerUser = auth()->user();
    $headerSurface = $surface === 'legacy' ? app(\App\Support\Ui\RoleHome::class)->primarySurface($headerUser) : $surface;
    $searcher = app(\App\Domain\Search\HeaderSearch::class);
    $searchScope = $searcher->resolve($headerUser, $surface === 'legacy' ? null : $surface) ?? 'passenger';
    $searchHint = [
        'passenger' => ['Search your trips and places', 'Search your trips and saved places'],
        'driver' => ['Search your trips', 'Search your trips'],
        'fleet' => ['Search vehicles and drivers', 'Search your fleet vehicles and drivers'],
        'ops' => ['Search rides, drivers and fleets', 'Search rides, drivers, vehicles and fleets'],
        'business' => ['Search the account trips', 'Search the business account trips'],
    ][$searchScope];
    $nav = app(\App\Support\Ui\HeaderNav::class);
    $homeRoute = \App\Support\Ui\RoleHome::HOME_ROUTES[$headerSurface] ?? 'dashboard';
    $brandHref = $surface === 'legacy' ? route('dashboard') : (Route::has($homeRoute) ? route($homeRoute) : url('/'));
    $logoH = 52;
    $logoW = (int) round($logoH * 1181 / 886);
@endphp
<x-dot.header
    id="eh-header"
    :brand-href="$brandHref"
    brand-label="Dot.Ehail home"
    :nav="$nav->primary($surface, $headerUser)"
    :nav-label="$navLabel ?? 'Primary'"
    :account="$nav->account($headerSurface, $headerUser, $theme ?? true)"
    :drawer-target="$adopt ?? null"
    :context="$context ?? null"
>
    <x-slot:brand>
        <img class="dh-logo-day" src="{{ asset('images/logo.png') }}" alt="Dot.Ehail" width="{{ $logoW }}" height="{{ $logoH }}">
        <img class="dh-logo-night" src="{{ asset('images/logo-light.png') }}" alt="" aria-hidden="true" width="{{ $logoW }}" height="{{ $logoH }}">
    </x-slot:brand>
    <x-slot:search>
        <x-dot.search
            name="q"
            :action="route('search')"
            :label="$searchHint[1]"
            :placeholder="$searchHint[0]"
            hint="Ctrl K"
            results-nav
            enter-first
            escape-clears
            loading-text="Searching"
            :hidden-fields="['scope' => $searchScope]"
            :root-attributes="['data-eh-search' => route('search.suggest'), 'data-eh-search-scope' => $searchScope]"
            :value="request()->routeIs('search') ? (string) request('q') : ''" />
    </x-slot:search>
    <x-slot:tools>
        {{-- The rider's and the driver's safety control stays in the bar at every width (it is also a tab on phones). Pages with a trip in progress replace it with SOS through the layout's `safety` slot. --}}
        @if (in_array($surface, ['passenger', 'driver'], true))
            @isset($safety){{ $safety }}@else @include('layouts.partials.eh-safety', ['safetyRoute' => $surface === 'driver' ? 'drive.safety' : 'ride.safety']) @endisset
        @endif
        @include('layouts.partials.eh-apps', ['appsModel' => $appsModel ?? null])
        @if ($surface === 'legacy')
            <livewire:notification-bell />
        @elseif (in_array($surface, ['passenger', 'driver', 'ops'], true))
            <x-comms::bell :surface="$surface" />
        @endif
    </x-slot:tools>
</x-dot.header>
