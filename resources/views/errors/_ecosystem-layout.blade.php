<!DOCTYPE html>
{{-- Error pages for every status (403, 404, 419, 429, 500, 503) in Dot.Ehail tokens; CSS only, no script, no third-party host.
     At most three destinations: the bar's sign-in (or the app) and the page's own actions. The list of every Dot
     product was removed (jury 81, R-46 polish D-2210): a person who hit a dead link needs a way back, not a catalogue. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>{{ $code }} @yield('title') | Dot.Ehail</title>
        <meta name="robots" content="noindex">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        @php
            $viteManifestPath = public_path('build/manifest.json');
            $viteEntries = [];
            if (file_exists($viteManifestPath)) {
                $viteManifest = json_decode(file_get_contents($viteManifestPath), true) ?? [];
                foreach (['resources/css/app.css'] as $entry) {
                    if (isset($viteManifest[$entry])) {
                        $viteEntries[] = $entry;
                    }
                }
            }

            $logoLightPath = null;
            foreach (['images/logo-light.png', null] as $logoLightCandidate) {
                if ($logoLightCandidate && file_exists(public_path($logoLightCandidate))) {
                    $logoLightPath = $logoLightCandidate;
                    break;
                }
            }
        @endphp
        @if (!empty($viteEntries))
            @vite($viteEntries)
        @endif
    </head>
    <body>
        <div class="eh-error">
            @hasSection('bar')
                @yield('bar')
            @else
            <header class="eh-error__bar">
                <x-eh.logo href="{{ url('/') }}" />
                @auth
                    <x-eh.button size="sm" :href="Route::has('home') ? route('home') : route('dashboard')">Open the app</x-eh.button>
                @else
                    @if (Route::has('login'))
                        <x-eh.button size="sm" variant="secondary" :href="route('login')">Sign in</x-eh.button>
                    @endif
                @endauth
            </header>
            @endif

            <main id="main" class="eh-error__main">
                <div class="eh-error__line" aria-hidden="true"><span></span><i></i><span></span></div>
                <h1 class="eh-error__title">@yield('title')</h1>
                <p class="eh-error__message">@yield('message') <span class="eh-error__code">(Error {{ $code }})</span></p>
                <div class="eh-error__actions">@yield('actions')</div>
                @yield('after')

            </main>

            <footer class="eh-error__foot">
                <p>&copy; {{ date('Y') }} Dot.Ehail. In an emergency call <a href="tel:10111">10111</a> or <a href="tel:112">112</a>.</p>
                @if (Route::has('contact'))
                    <a href="{{ route('contact') }}">Contact support</a>
                @endif
            </footer>
        </div>
    </body>
</html>
