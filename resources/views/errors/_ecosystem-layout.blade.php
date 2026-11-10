<!DOCTYPE html>
{{-- Error pages for every status (403, 404, 419, 429, 500, 503) in Dot.Ehail tokens; CSS only, no script, no third-party host. --}}
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

            // Every Dot platform, pulled from the shared ecosystem registry
            // (config/ecosystem.php, identical across all platforms) rather
            // than a fixed hand-picked subset -- add a platform to the
            // registry once and it shows up here automatically everywhere.
            // Self-exclusion uses this generator-verified literal name
            // rather than config('app.name'), since not every platform's
            // .env reliably has APP_NAME set correctly.
            $currentPlatformName = 'Dot.Ehail';
            $discover = collect(config('ecosystem.platforms', []))
                ->reject(fn ($p) => ($p['name'] ?? null) === $currentPlatformName)
                ->reject(fn ($p) => ($p['active'] ?? true) === false)
                ->reject(fn ($p, $k) => ($p['internal'] ?? false) === true || in_array($k, ['memory', 'brain', 'dopemine'], true))
                ->values()
                ->all();

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

            <main id="main" class="eh-error__main">
                <div class="eh-error__line" aria-hidden="true"><span></span><i></i><span></span></div>
                <h1 class="eh-error__title">@yield('title')</h1>
                <p class="eh-error__message">@yield('message') <span class="eh-error__code">(Error {{ $code }})</span></p>
                <div class="eh-error__actions">@yield('actions')</div>

                @if (!empty($discover))
                    <section class="eh-error__eco" aria-labelledby="eco-title">
                        <h2 class="eh-error__eco-title" id="eco-title">While you're here, the rest of the Dot Ecosystem ({{ count($discover) }})</h2>
                        <ul>
                            @foreach ($discover as $platform)
                                <li><a href="{{ $platform['url'] }}">{{ $platform['name'] }}</a></li>
                            @endforeach
                        </ul>
                    </section>
                @endif
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
