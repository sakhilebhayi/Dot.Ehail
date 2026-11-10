{{--
    Public fare calculator (/fares): set two points, read every line of every fare before signing in. Prices come
    from pricing's public estimate endpoint (labelled estimates, nothing stored, nothing bookable from here).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="eh" data-theme-key="eh-theme" data-sw="on">
<head>
    @include('layouts.partials.eh-head', [
        'title' => 'Fare calculator',
        'description' => 'See the full Dot.Ehail fare for a trip, line by line, before you sign in.',
        'viteEntries' => ['resources/css/app.css', 'resources/js/eh/shell.js', 'resources/css/passenger.css', 'resources/js/passenger/fares.js'],
    ])
</head>
<body>
    <a class="eh-skip" href="#main">Skip to content</a>
    <div class="pax-public">
        <header class="pax-public__bar">
            <x-eh.logo :href="url('/')" />
            @auth
                <x-eh.button size="sm" :href="route('ride.home')">Book a ride</x-eh.button>
            @else
                <x-eh.button size="sm" variant="secondary" :href="route('login')">Sign in to ride</x-eh.button>
            @endauth
        </header>
        <main id="main" tabindex="-1">
            <div class="pax-stage" data-pax-fares>
                <x-eh.map-frame id="pax-map" label="Map: tap to set the pickup, then the destination" :bleed="true" height="100%" message="The map loads after the calculator." />
                <x-eh.sheet id="pax-fares" snap="half" :dock="true" aria-labelledby="pax-fares-title">
                    <div class="pax-calc-head">
                        <h1 id="pax-fares-title">See the fare first</h1>
                        <p>Pick two points and read every part of the price: base fare, distance, time, fees, and the total. This is the same calculation a booking uses.</p>
                    </div>
                    @if ($canSearch)
                        <x-eh.place-field id="pax-pickup" label="Pickup" placeholder="Street and suburb" dot="pickup" data-pax-search="pickup" />
                        <x-eh.place-field id="pax-dropoff" label="Destination" placeholder="Where to?" dot="dropoff" data-pax-search="dropoff" />
                    @else
                        <ol class="pax-route">
                            <li class="pax-route__stop"><span class="pax-route__dot" aria-hidden="true"></span><span class="pax-route__text"><span class="pax-route__label">Pickup</span><span class="pax-route__address" data-pax-calc="pickup" data-empty="true">Tap the map, or use your location</span></span></li>
                            <li class="pax-route__stop pax-route__stop--drop"><span class="pax-route__dot" aria-hidden="true"></span><span class="pax-route__text"><span class="pax-route__label">Destination</span><span class="pax-route__address" data-pax-calc="dropoff" data-empty="true">Then tap where you are going</span></span></li>
                        </ol>
                        <p class="pax-rule">Address search is for signed-in riders. <a class="eh-link" href="{{ route('login') }}">Sign in</a> to search by street name.</p>
                    @endif
                    <div class="pax-tap">
                        <x-eh.button variant="ghost" size="sm" icon="locate" data-pax-locate>Use my location as pickup</x-eh.button>
                        <x-eh.button variant="ghost" size="sm" icon="refresh" data-pax-reset>Start again</x-eh.button>
                    </div>
                    <p class="pax-field__status" data-pax-locate-status role="status"></p>
                    <div data-pax-estimate aria-live="polite">
                        <p class="pax-rule">Prices appear here once both points are set.</p>
                    </div>
                    <noscript><p class="pax-rule">The calculator needs JavaScript. Categories offered: {{ $categories->pluck('name')->join(', ') }}.</p></noscript>
                </x-eh.sheet>
            </div>
        </main>
    </div>
    <template id="pax-icons">
        @foreach (['car', 'car-sedan', 'car-xl', 'accessibility', 'luggage', 'route', 'chevron-down', 'info', 'warning', 'refresh'] as $iconName)
            <span data-icon="{{ $iconName }}"><x-eh.icon :name="$iconName" /></span>
        @endforeach
    </template>
    <div id="pax-live" class="pax-live" aria-live="polite"></div>
    <x-eh.toasts />
    <script type="application/json" data-geo-config>@json($geoConfig)</script>
    @php $paxIsland = [
        'canSearch' => $canSearch,
        'urls' => ['estimate' => route('pricing.estimate'), 'book' => route('ride.home'), 'login' => route('login')],
    ]; @endphp
    <script type="application/json" id="pax-fares-data">@json($paxIsland)</script>
</body>
</html>
