{{--
    Driver app shell (/drive). Minimal distraction: one big state header (slot "state": online, offline, on a trip),
    large type, bottom bar (Drive, Earnings, Trips, Account). Theme defaults to "night": dark from 18:00 to 06:00 or
    when the phone is dark, light by day for sun on the windscreen; the driver can pin Light or Dark.
--}}
@php
    $navItems = app(\App\Support\Ui\Navigation::class)->items('driver', auth()->user());
    $navLabel = 'Driver';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh" data-theme-key="eh-theme-drive" data-theme-default="night" data-sw="on">
<head>
    @include('layouts.partials.eh-head', ['title' => $title ?? null])
    @vite(['resources/css/comms/comms.css'])
    {{ $head ?? '' }}
</head>
<body>
    <a class="eh-skip" href="#main">Skip to content</a>
    <div class="eh-app eh-drive {{ ($bleed ?? false) ? 'eh-app--bleed' : '' }}" data-surface="driver">
        <header class="eh-topbar">
            <div class="eh-topbar__brand"><x-eh.logo :href="Route::has('drive.home') ? route('drive.home') : url('/')" /></div>
            @include('layouts.partials.eh-topnav')
            <div class="eh-topbar__slot">
                @isset($safety){{ $safety }}@else @include('layouts.partials.eh-safety', ['safetyRoute' => 'drive.safety']) @endisset
                <x-comms::bell surface="driver" />
                @include('layouts.partials.eh-account', ['surface' => 'driver'])
            </div>
        </header>
        @isset($state){{ $state }}@endisset
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
