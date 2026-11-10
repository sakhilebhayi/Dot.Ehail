{{--
    Public trip-share page /t/{token} (R-46 ppass, D-2011). Read by someone without an account, often worried and on a
    phone: the state first, then when, then which car, then who to call. Built in Route Line with its own head, because
    this route has no session: no CSRF token, no cookie, no stored theme choice (the page follows the device theme).
    It names the two areas of the trip, never an address, and draws the planned road line with both ends cut off.
--}}
@php
    $sharer = $payload['sharer_first_name'] ?? null;
    $numbers = config('ehail_safety.emergency_numbers.'.config('ehail_safety.default_market', 'ZA'), []);
    $live = $payload && ! $payload['ended'];
@endphp
<!DOCTYPE html>
<html lang="en-ZA" class="eh">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="description" content="A trip someone shared with you on Dot.Ehail: where the car is, when it arrives, the driver's first name and the vehicle.">
    <title>{{ $payload ? 'Shared trip' : 'Link not available' }} | Dot.Ehail</title>
    <meta name="theme-color" content="#f1f4f0" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#1a2a24" media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="preload" href="{{ asset('fonts/barlow-latin-400-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ asset('fonts/barlow-latin-600-normal.woff2') }}" as="font" type="font/woff2" crossorigin>
    @if($live)
        <noscript><meta http-equiv="refresh" content="30"></noscript>
    @endif
    @vite($live ? ['resources/css/app.css', 'resources/css/share.css', 'resources/js/share/trip-share.js'] : ['resources/css/app.css', 'resources/css/share.css'])
</head>
<body class="shr">
<header class="shr-top">
    <x-eh.logo :size="28" />
    <span class="shr-top__tag">Shared trip</span>
</header>

@if(! $payload)
    <main class="shr-gone">
        <h1 class="shr-state">This link is not available</h1>
        <p>It has expired, was turned off by the person who shared it, or was mistyped. Trip links stop working 30 minutes after the trip ends.</p>
        <p class="shr-fine">If you are worried about someone, try to reach them first. Dot.Ehail does not send police or an ambulance.</p>
        <div class="shr-calls">
            @foreach($numbers as $entry)
                <a class="shr-call" href="tel:{{ $entry['number'] }}"><span>{{ $entry['label'] }}</span><strong class="eh-num">{{ $entry['number'] }}</strong></a>
            @endforeach
        </div>
    </main>
@else
    <main class="shr-main{{ $live ? '' : ' shr-main--ended' }}" data-share data-status-url="{{ $statusUrl }}" data-route="{{ $map['route'] ?? '' }}">
        @if($live)
            <x-eh.map-frame class="shr-map" id="share-map" label="Map of the shared trip" height="100%" :bleed="true" message="The map loads here. The trip details below do not need it.">
                <noscript><p class="shr-map__noscript">The map needs JavaScript. This page still reloads every 30 seconds.</p></noscript>
            </x-eh.map-frame>
        @endif

        <div class="shr-panel">
            <section class="shr-head" aria-live="polite">
                <p class="shr-who">{{ $sharer }}{{ $payload['shared_by'] === 'driver' ? ', the driver,' : '' }} is sharing this trip with you.</p>
                <h1 class="shr-state" data-field="state_label">{{ $payload['state_label'] }}</h1>
                <p class="shr-eta" data-field="eta" @if(! $payload['eta_text']) hidden @endif>{{ $payload['eta_text'] }}</p>
            </section>

            <ol class="shr-route" aria-label="Route, by area" data-field="route" @if(! $payload['from_area'] && ! $payload['to_area']) hidden @endif>
                <li class="shr-route__stop"><span class="shr-route__dot" aria-hidden="true"></span><span class="shr-route__label">From</span><span class="shr-route__area" data-field="from_area">{{ $payload['from_area'] ?? 'Area not given' }}</span></li>
                <li class="shr-route__stop shr-route__stop--drop"><span class="shr-route__dot" aria-hidden="true"></span><span class="shr-route__label">To</span><span class="shr-route__area" data-field="to_area">{{ $payload['to_area'] ?? 'Area not given' }}</span></li>
            </ol>

            <section class="shr-car" aria-label="Car and driver">
                <p class="shr-plate" data-field="registration" @if(! $payload['vehicle']) hidden @endif>{{ $payload['vehicle']['registration'] ?? '' }}</p>
                <div class="shr-car__text">
                    <p class="shr-car__vehicle" data-field="vehicle">{{ $payload['vehicle']['description'] ?? 'No car assigned yet' }}</p>
                    <p class="shr-car__driver">Driver: <span data-field="driver">{{ $payload['driver_first_name'] ?? 'not assigned yet' }}</span></p>
                </div>
            </section>

            @if($live)
                <p class="shr-note" data-field="position">
                    @if($payload['approximate_position'])
                        The car's position on the map is approximate, to about 100 m. Last seen {{ $payload['approximate_position']['time'] }} {{ $payload['time_zone'] }}.
                    @else
                        The car's position is not available right now.
                    @endif
                </p>
            @endif

            <section class="shr-worry">
                <h2 class="shr-worry__title">Worried about {{ $sharer }}?</h2>
                <p>Try to reach them first. Dot.Ehail does not send police or an ambulance; call them yourself.</p>
                <div class="shr-calls">
                    @foreach($numbers as $entry)
                        <a class="shr-call" href="tel:{{ $entry['number'] }}"><span>{{ $entry['label'] }}</span><strong class="eh-num">{{ $entry['number'] }}</strong></a>
                    @endforeach
                </div>
            </section>

            <p class="shr-fine">
                This link stops working at <time data-field="expires" datetime="{{ $payload['expires_at'] }}">{{ $payload['expires_time'] }}</time> {{ $payload['time_zone'] }}.
                Updated <time data-field="checked" datetime="{{ $payload['checked_at'] }}">{{ $payload['checked_time'] }}</time> {{ $payload['time_zone'] }}.
                <span class="shr-stale" data-field="stale" role="status"></span>
            </p>
        </div>
    </main>

    @if($live)
        <script type="application/json" data-geo-config>@json($map['config'])</script>
        <script type="application/json" data-share-state>@json($payload)</script>
    @endif
@endif
</body>
</html>
