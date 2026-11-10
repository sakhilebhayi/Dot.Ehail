<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <meta name="description" content="A trip someone shared with you on Dot.Ehail: progress, driver first name and vehicle.">
    <title>{{ $payload ? 'Shared trip' : 'Link not available' }} | Dot.Ehail</title>
    @if($payload && ! $payload['ended'])
        <noscript><meta http-equiv="refresh" content="30"></noscript>
    @endif
    @include('safety.partials.public-style')
</head>
<body>
<main class="eh-wrap">
    <p class="eh-mark">Dot.Ehail shared trip</p>

    @if(! $payload)
        <div class="eh-board">
            <section class="eh-state">
                <h1>This link is not available</h1>
                <p>It has expired, was turned off by the person who shared it, or was mistyped. Trip links stop working 30 minutes after the trip ends.</p>
            </section>
        </div>
    @else
        <div class="eh-board" data-share data-status-url="{{ $statusUrl }}">
            <section class="eh-state" aria-live="polite">
                <h1 data-field="state_label">{{ $payload['state_label'] }}</h1>
                <p>{{ $payload['passenger_first_name'] }} is sharing this trip with you.</p>
            </section>

            <section>
                <span class="eh-label">Registration</span>
                <div class="eh-readout" data-field="registration">{{ $payload['vehicle']['registration'] ?? 'Not assigned yet' }}</div>
                <div class="eh-value" data-field="vehicle">{{ $payload['vehicle']['description'] ?? '' }}</div>
            </section>

            <div class="eh-pair">
                <div>
                    <span class="eh-label">Driver</span>
                    <div class="eh-value" data-field="driver">{{ $payload['driver_first_name'] ?? 'Not assigned yet' }}</div>
                </div>
                <div>
                    <span class="eh-label">Arriving in</span>
                    <div class="eh-value{{ $payload['eta_seconds'] !== null ? ' eh-mono' : '' }}" data-field="eta">{{ $payload['eta_seconds'] !== null ? max(1, (int) round($payload['eta_seconds'] / 60)).' min' : 'Not available' }}</div>
                </div>
            </div>

            <section>
                <span class="eh-label">Approximate car position (to about 100 m)</span>
                @if($payload['approximate_position'])
                    <div class="eh-value eh-mono" data-field="position">{{ number_format($payload['approximate_position']['lat'], 3) }}, {{ number_format($payload['approximate_position']['lng'], 3) }}</div>
                    <a data-field="map" rel="noopener noreferrer" target="_blank" href="https://www.openstreetmap.org/?mlat={{ $payload['approximate_position']['lat'] }}&amp;mlon={{ $payload['approximate_position']['lng'] }}#map=15/{{ $payload['approximate_position']['lat'] }}/{{ $payload['approximate_position']['lng'] }}">Open in a map</a>
                @else
                    <div class="eh-value" data-field="position">Not available right now</div>
                @endif
            </section>

            <section class="eh-note">
                <p>Worried about {{ $payload['passenger_first_name'] }}? Try to reach them first. Dot.Ehail does not send police or an ambulance; call them yourself:</p>
                <div class="eh-calls">
                    @foreach(config('ehail_safety.emergency_numbers.'.config('ehail_safety.default_market', 'ZA'), []) as $entry)
                        <a class="eh-call" href="tel:{{ $entry['number'] }}">{{ $entry['label'] }}: <span class="eh-mono">&nbsp;{{ $entry['number'] }}</span></a>
                    @endforeach
                </div>
            </section>
        </div>

        <p class="eh-fine">This link stops working at <time class="eh-mono" data-field="expires" datetime="{{ $payload['expires_at'] }}">{{ \Illuminate\Support\Carbon::parse($payload['expires_at'])->timezone(\App\Support\Ui\LocalTime::timezone())->format('H:i, j M') }}</time>. Updated <time class="eh-mono" data-field="checked" datetime="{{ $payload['checked_at'] }}">{{ \Illuminate\Support\Carbon::parse($payload['checked_at'])->timezone(\App\Support\Ui\LocalTime::timezone())->format('H:i:s') }}</time>. <span data-field="stale"></span></p>

        <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            var board = document.querySelector('[data-share]');
            if (!board || !window.fetch) { return; }
            var url = board.getAttribute('data-status-url');
            var timer = null;
            function set(name, text) { var el = board.parentNode.querySelector('[data-field="' + name + '"]'); if (el) { el.textContent = text; } return el; }
            function pad(n) { return (n < 10 ? '0' : '') + n; }
            // Times are rendered by the server in its own timezone; show them in the viewer's.
            var exp = board.parentNode.querySelector('[data-field="expires"]');
            if (exp) { var e = new Date(exp.getAttribute('datetime')); exp.textContent = pad(e.getHours()) + ':' + pad(e.getMinutes()) + ', ' + e.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }); }
            function tick() {
                fetch(url, { credentials: 'omit', cache: 'no-store', headers: { 'Accept': 'application/json' } })
                    .then(function (r) { if (r.status === 404) { throw 'gone'; } return r.json(); })
                    .then(function (p) {
                        set('state_label', p.state_label);
                        set('driver', p.driver_first_name || 'Not assigned yet');
                        set('registration', p.vehicle ? p.vehicle.registration : 'Not assigned yet');
                        set('vehicle', p.vehicle ? p.vehicle.description : '');
                        var eta = set('eta', p.eta_seconds === null ? 'Not available' : Math.max(1, Math.round(p.eta_seconds / 60)) + ' min'); if (eta) { eta.className = p.eta_seconds === null ? 'eh-value' : 'eh-value eh-mono'; }
                        set('position', p.approximate_position ? p.approximate_position.lat.toFixed(3) + ', ' + p.approximate_position.lng.toFixed(3) : 'Not available right now');
                        var d = new Date(p.checked_at); set('checked', pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()));
                        set('stale', '');
                        if (p.ended) { clearInterval(timer); }
                    })
                    .catch(function (e) {
                        if (e === 'gone') { clearInterval(timer); set('state_label', 'This link is no longer available'); return; }
                        var s = set('stale', 'Could not refresh; showing the last known state.'); if (s) { s.className = 'eh-stale'; }
                    });
            }
            @if(! $payload['ended'])
            timer = setInterval(tick, 5000);
            @endif
        })();
        </script>
    @endif
</main>
</body>
</html>
